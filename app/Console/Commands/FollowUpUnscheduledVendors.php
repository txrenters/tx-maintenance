<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpUnscheduledVendors extends Command
{
    protected $signature = 'vendors:followup-unscheduled';

    protected $description = 'Text vendors who still have no service schedule 3 business days after being assigned.';

    /**
     * Business days after assignment before the vendor is nudged.
     */
    private const FOLLOWUP_AFTER_BUSINESS_DAYS = 3;

    /**
     * The follow-up message, approved by operations (Chana).
     */
    private const MESSAGE = "Hello,\n"
        ."We noticed that a service schedule has not yet been set for this work order after 3 days.\n"
        ."Please make sure to update the work order by creating a schedule under the Service Schedule tab on your dashboard once confirmed with the tenant.\n"
        ."Once the appointment has been scheduled, please ensure that the completed tasks are checked off accordingly so the work order status can be updated to Scheduled.\n"
        ."Please complete this update as soon as possible and let us know once it has been done.\n"
        .'Thank you.';

    public function handle(): int
    {
        // Gated off by default so local/testing never texts a real vendor.
        if (! config('services.twilio.schedule_followup_sms')) {
            $this->info('Vendor schedule follow-up is disabled (SCHEDULE_FOLLOWUP_SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $assignments = DB::table('work_order_vendors')
            ->whereNull('schedule_followup_sent_at')
            ->get(['work_order_id', 'vendor_id', 'created_at']);

        $sent = 0;

        foreach ($assignments as $assignment) {
            if (! $this->isDueForFollowUp($assignment)) {
                continue;
            }

            $workOrder = WorkOrder::query()->find($assignment->work_order_id);

            // Only open work orders that still have no service schedule.
            if (! $workOrder || $workOrder->status !== 'Open' || $workOrder->service_schedules()->exists()) {
                continue;
            }

            $vendor = Vendor::query()->with('user')->find($assignment->vendor_id);

            if (! $vendor) {
                continue;
            }

            // "OWNER VENDOR" is the owner handling the repair themselves — they
            // have no vendor dashboard to update, so never nag (not even into
            // the conversation thread). Stamp it so it is not rescanned daily.
            if ($vendor->isOwnerPlaceholder()) {
                DB::table('work_order_vendors')
                    ->where('work_order_id', $assignment->work_order_id)
                    ->where('vendor_id', $assignment->vendor_id)
                    ->whereNull('schedule_followup_sent_at')
                    ->update(['schedule_followup_sent_at' => now()]);

                continue;
            }

            // Claim this follow-up atomically so an overlapping run or a retry
            // never texts the same assignment twice.
            $claimed = DB::table('work_order_vendors')
                ->where('work_order_id', $assignment->work_order_id)
                ->where('vendor_id', $assignment->vendor_id)
                ->whereNull('schedule_followup_sent_at')
                ->update(['schedule_followup_sent_at' => now()]);

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
     * At least 3 business days must have passed since the vendor was assigned.
     */
    private function isDueForFollowUp(object $assignment): bool
    {
        if (blank($assignment->created_at)) {
            return false;
        }

        return Carbon::parse($assignment->created_at)->diffInWeekdays(now()) >= self::FOLLOWUP_AFTER_BUSINESS_DAYS;
    }

    /**
     * Post the follow-up into the WOC<->vendor conversation thread and text the
     * vendor, mirroring the assignment-SMS path so the message threads with it
     * and the vendor's reply routes to the same number.
     */
    private function followUp(WorkOrder $workOrder, Vendor $vendor): void
    {
        $vendorNumber = $this->toE164($vendor->phone);

        // sender_number is the WOC/company number so the portal renders this as
        // a message from the coordinator, not the vendor.
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $conversation = Conversation::create([
            'message' => self::MESSAGE,
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

        SendConversationMessageJob::dispatch($vendorNumber, $wocNumber, self::MESSAGE, null, $conversation->id);
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
