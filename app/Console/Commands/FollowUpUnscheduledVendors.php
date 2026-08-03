<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\VendorPortalLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpUnscheduledVendors extends Command
{
    protected $signature = 'vendors:followup-unscheduled';

    protected $description = 'Text vendors every day, starting right after assignment, until they set a service schedule.';

    /**
     * The follow-up message, approved by operations (Chana).
     */
    private const MESSAGE = "Hello,\n"
        ."We noticed that a service schedule has not yet been set for this work order.\n"
        ."Please make sure to update the work order by creating a schedule under the Service Schedule tab on your dashboard once confirmed with the tenant.\n"
        ."Once the appointment has been scheduled, please ensure that the completed tasks are checked off accordingly so the work order status can be updated to Scheduled.\n"
        ."Please complete this update as soon as possible and let us know once it has been done.\n"
        .'Thank you.';

    /**
     * Runs daily. Every unscheduled assignment created after go-live is nudged
     * once per day until a service schedule appears (or the work order closes).
     *
     * Two columns keep this safe:
     *  - schedule_followup_sent_at   permanent "handled / excluded" baseline —
     *    the fresh-start backfill stamps the pre-existing backlog, and the OWNER
     *    VENDOR placeholder is stamped here too, so neither is ever nagged.
     *  - schedule_followup_last_sent_at  the once-per-day throttle.
     */
    public function handle(): int
    {
        // Gated off by default so local/testing never texts a real vendor.
        if (! config('services.twilio.schedule_followup_sms')) {
            $this->info('Vendor schedule follow-up is disabled (SCHEDULE_FOLLOWUP_SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $startOfToday = now()->startOfDay();

        // Only assignments that are not excluded (sent_at is the backlog /
        // placeholder baseline) and have not already been nudged today.
        $assignments = DB::table('work_order_vendors')
            ->whereNull('schedule_followup_sent_at')
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('schedule_followup_last_sent_at')
                    ->orWhere('schedule_followup_last_sent_at', '<', $startOfToday);
            })
            ->get(['work_order_id', 'vendor_id']);

        $sent = 0;

        foreach ($assignments as $assignment) {
            $workOrder = WorkOrder::query()->find($assignment->work_order_id);

            // Only open work orders that still have no service schedule.
            if (! $workOrder || $workOrder->status !== 'Open' || $workOrder->service_schedules()->exists()) {
                continue;
            }

            // A WOC can mute this work order's vendor automation from the vendor
            // conversation tab; skip the nudge while paused (it resumes if the
            // WOC switches it back on and the schedule is still missing).
            if ($workOrder->automationPausedFor('vendor')) {
                continue;
            }

            $vendor = Vendor::query()->with('user')->find($assignment->vendor_id);

            if (! $vendor) {
                continue;
            }

            // "OWNER VENDOR" is the owner handling the repair themselves — they
            // have no vendor dashboard to update, so never nag (not even into
            // the conversation thread). Stamp the exclusion baseline so it is
            // never rescanned.
            if ($vendor->isOwnerPlaceholder()) {
                DB::table('work_order_vendors')
                    ->where('work_order_id', $assignment->work_order_id)
                    ->where('vendor_id', $assignment->vendor_id)
                    ->whereNull('schedule_followup_sent_at')
                    ->update(['schedule_followup_sent_at' => now()]);

                continue;
            }

            // Claim today's nudge atomically so an overlapping run or a retry
            // never texts the same assignment twice on the same day.
            $claimed = DB::table('work_order_vendors')
                ->where('work_order_id', $assignment->work_order_id)
                ->where('vendor_id', $assignment->vendor_id)
                ->where(function ($query) use ($startOfToday) {
                    $query->whereNull('schedule_followup_last_sent_at')
                        ->orWhere('schedule_followup_last_sent_at', '<', $startOfToday);
                })
                ->update(['schedule_followup_last_sent_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $this->followUp($workOrder, $vendor);
                $sent++;
            } catch (\Throwable $exception) {
                Log::error('Vendor schedule follow-up failed to send.', [
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Vendor schedule follow-ups sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Post the follow-up into the WOC<->vendor conversation thread and text the
     * vendor, mirroring the assignment-SMS path so the message threads with it
     * and the vendor's reply routes to the same number.
     */
    private function followUp(WorkOrder $workOrder, Vendor $vendor): void
    {
        $vendorNumber = $this->toE164($vendor->phone);

        $message = $this->messageFor($workOrder, $vendor);

        // sender_number is the WOC/company number so the portal renders this as
        // a message from the coordinator, not the vendor.
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $wocNumber ?: null,
            'receiver_number' => $vendorNumber,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'read_by_vendor' => false,
            'is_mms' => false,
        ]);

        // No usable numbers: the message is still logged in the vendor's portal
        // thread, but there is nothing to text.
        if (blank($vendorNumber) || blank($wocNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($vendorNumber, $wocNumber, $message, null, $conversation->id);
    }

    /**
     * The approved follow-up copy, with this assignment's magic link appended so
     * "update the work order on your dashboard" is one tap away. The link line is
     * dropped when no token could be issued rather than losing the nudge.
     */
    private function messageFor(WorkOrder $workOrder, Vendor $vendor): string
    {
        $linkBlock = VendorPortalLinkService::linkBlock(
            app(VendorPortalLinkService::class)->link($workOrder, $vendor)
        );

        return $linkBlock ? self::MESSAGE."\n\n".$linkBlock : self::MESSAGE;
    }

    /**
     * Normalize a US phone number to E.164 (+1XXXXXXXXXX); null if unusable.
     */
    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits ? '+'.$digits : null;
    }
}
