<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\VendorPortalLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class FollowUpUnscheduledVendors extends Command
{
    protected $signature = 'vendors:followup-unscheduled';

    protected $description = 'Text vendors every day, starting right after assignment, until they set a service schedule.';

    /**
     * The first notice, approved by operations (Chana). Sent on the first nudge
     * of an assignment only — repeats use FOLLOW_UP_MESSAGES so the vendor
     * never receives the identical canned text day after day.
     */
    private const FIRST_MESSAGE = "Hello,\n"
        ."We noticed that a service schedule has not yet been set for this work order.\n"
        ."Please make sure to update the work order by creating a schedule under the Service Schedule tab on your dashboard once confirmed with the tenant.\n"
        ."Once the appointment has been scheduled, please ensure that the completed tasks are checked off accordingly so the work order status can be updated to Scheduled.\n"
        ."Please complete this update as soon as possible and let us know once it has been done.\n"
        .'Thank you.';

    /**
     * Polite rephrasings of the first notice — same content (set the schedule
     * under the Service Schedule tab once confirmed with the tenant, check off
     * the completed tasks so the status moves to Scheduled, tell us when done),
     * different voice each day. Rotated by how many nudges this assignment has
     * already received, so consecutive days never read as the same automation.
     *
     * @var array<int, string>
     */
    private const FOLLOW_UP_MESSAGES = [
        "Hello,\n"
            ."Just following up on our earlier message - we still don't see a service schedule for this work order.\n"
            ."Once you've confirmed a time with the tenant, please add it under the Service Schedule tab on your dashboard, and check off the completed tasks so the status can be updated to Scheduled.\n"
            ."We'd appreciate an update as soon as you're able. Thank you!",
        "Hi,\n"
            ."A quick reminder about this work order - the service schedule still hasn't been added.\n"
            ."When you and the tenant have agreed on a time, please enter it under the Service Schedule tab on your dashboard and mark the completed tasks so we can move the status to Scheduled.\n"
            ."Please let us know once it's done. Thank you so much!",
        "Hello,\n"
            ."We wanted to check in, as this work order is still showing without a service schedule.\n"
            ."If you've already confirmed with the tenant, please take a moment to record the appointment under the Service Schedule tab on your dashboard and tick off the completed tasks so the status updates to Scheduled.\n"
            .'If something is holding this up, just reply here and let us know. Thank you!',
        "Hi,\n"
            ."Checking in again on this work order - we're still waiting on the service schedule.\n"
            ."Please confirm a visit time with the tenant if you haven't yet, then add it under the Service Schedule tab on your dashboard and check off the completed tasks so the status can change to Scheduled.\n"
            ."A quick note once that's in would be much appreciated. Thanks for your help!",
        "Hello,\n"
            ."A friendly nudge on this one - the service schedule for this work order hasn't come through yet.\n"
            ."Once the time is set with the tenant, please log it under the Service Schedule tab on your dashboard and mark the completed tasks so the work order can move to Scheduled.\n"
            ."Thank you for keeping this moving - please update us when it's done.",
    ];

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

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'vendor',
            'vendor_schedule_follow_up_sms',
            $vendorNumber,
            $workOrder,
            $message,
            ['vendor_id' => $vendor->id, 'conversation_id' => $conversation->id],
        );
    }

    /**
     * Today's copy — the approved first notice on the first nudge, a rotating
     * rephrasing on repeats — with this assignment's magic link appended so
     * "update the work order on your dashboard" is one tap away. The link line is
     * dropped when no token could be issued rather than losing the nudge.
     */
    private function messageFor(WorkOrder $workOrder, Vendor $vendor): string
    {
        $body = $this->messageBody($this->priorNudgeCount($workOrder, $vendor));

        $linkBlock = VendorPortalLinkService::linkBlock(
            app(VendorPortalLinkService::class)->link($workOrder, $vendor)
        );

        return $linkBlock ? $body."\n\n".$linkBlock : $body;
    }

    /**
     * First nudge gets the approved first notice; every later nudge cycles
     * through the rephrasings, so no two consecutive days repeat the same text
     * and the exact first-notice wording is never reused.
     */
    private function messageBody(int $priorNudges): string
    {
        if ($priorNudges === 0) {
            return self::FIRST_MESSAGE;
        }

        return self::FOLLOW_UP_MESSAGES[($priorNudges - 1) % count(self::FOLLOW_UP_MESSAGES)];
    }

    /**
     * How many follow-up texts this assignment has already received, counted
     * from the automated-message ledger (no schema change needed). Fails safe
     * to 0 — a ledger read error falls back to the approved first notice rather
     * than blocking the nudge.
     */
    private function priorNudgeCount(WorkOrder $workOrder, Vendor $vendor): int
    {
        try {
            // Both int and string forms are matched because the JSON extraction
            // returns an int on SQLite and a string on MySQL.
            return Activity::query()
                ->where('log_name', AutomatedMessageLogService::LOG_NAME)
                ->where('event', 'vendor_schedule_follow_up_sms')
                ->where('subject_type', $workOrder->getMorphClass())
                ->where('subject_id', $workOrder->id)
                ->whereIn('properties->vendor_id', [$vendor->id, (string) $vendor->id])
                ->count();
        } catch (\Throwable $exception) {
            Log::warning('Vendor schedule follow-up ledger count failed; using the first-notice copy.', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
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
