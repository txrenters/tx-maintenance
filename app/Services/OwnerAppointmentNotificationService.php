<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerAppointmentNotificationService
{
    /**
     * Notify the property owner that a vendor has set the service appointment.
     *
     * Posts the update into the owner<->WOC conversation thread and texts the
     * owner, asking whether they'd like to join a call with the technician at
     * the appointment time or to approve the work order. Gated off by default,
     * fired at most once per schedule, and wrapped so a failure is logged but
     * never breaks schedule creation.
     */
    public function notify(ServiceSchedule $serviceSchedule): void
    {
        if (! config('services.twilio.owner_schedule_sms')) {
            return;
        }

        // Claim this schedule atomically so a retry or a reschedule can never
        // text the owner twice for the same appointment.
        $claimed = DB::table('service_schedules')
            ->where('id', $serviceSchedule->id)
            ->whereNull('owner_notified_at')
            ->update(['owner_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $this->send($serviceSchedule);
        } catch (\Throwable $exception) {
            Log::error('Owner appointment notification failed to send.', [
                'service_schedule_id' => $serviceSchedule->id,
                'work_order_id' => $serviceSchedule->work_order_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(ServiceSchedule $serviceSchedule): void
    {
        $serviceSchedule->loadMissing([
            'vendor.user',
            'work_order.owners',
            'work_order.requested_by',
            'work_order.building',
            'work_order.woc.wocNumber.twilioPhoneNumber',
        ]);

        $workOrder = $serviceSchedule->work_order;

        if (! $workOrder instanceof WorkOrder) {
            return;
        }

        // Text the real property owner (highest ownership stake), never the
        // management-company owner_id / managed_by.
        $owner = $workOrder->primaryOwner();

        // No owner on file: nothing to notify.
        if (! $owner instanceof Owner) {
            return;
        }

        $ownerNumber = $this->firstFilled($owner->mobile, $owner->phone);
        $fromNumber = $this->fromNumber($workOrder);
        $message = $this->message($serviceSchedule, $workOrder);

        // Log the update in the owner thread even when we cannot text, so the
        // coordinator sees the appointment update went out.
        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $ownerNumber ?: null,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        // No usable numbers: the message is logged in the owner thread, but there
        // is nothing to text.
        if (blank($ownerNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($ownerNumber, $fromNumber, $message, null, $conversation->id);
    }

    /**
     * The owner-facing appointment message: Chana's approved wording, plus the
     * ticket-required question about joining the technician call or approving.
     */
    private function message(ServiceSchedule $serviceSchedule, WorkOrder $workOrder): string
    {
        $vendorName = trim((string) ($serviceSchedule->vendor?->name
            ?: $serviceSchedule->vendor?->user?->name
            ?: 'the assigned vendor'));

        $when = $this->formatAppointment($serviceSchedule);
        $address = $this->propertyAddress($workOrder);
        $property = $address !== '' ? ' at '.$address : '';

        return "Hello,\n"
            ."We wanted to provide an update that the service appointment for your property{$property} has been scheduled with {$vendorName}.\n"
            .($when !== '' ? "Scheduled: {$when}\n" : '')
            ."The vendor will be proceeding with the service as scheduled. Will you be available at the appointment time for a phone call to speak with the technician directly, or to approve the work order? If so, please let us know and we can coordinate accordingly.\n"
            ."We will continue to provide updates once the service has been completed.\n"
            ."Thank you!\n"
            ."(Ref: WO#{$workOrder->work_order_no})";
    }

    /**
     * The property street address, matching the street-only form the WOC uses
     * (e.g. "3326 Jane Way"): the tenant's address, then the building's street
     * address, else empty so the message reads "for your property".
     */
    private function propertyAddress(WorkOrder $workOrder): string
    {
        $tenantAddress = trim((string) ($workOrder->requested_by?->address ?? ''));

        if ($tenantAddress !== '') {
            return $tenantAddress;
        }

        return trim((string) ($workOrder->building?->address ?? ''));
    }

    /**
     * Format the scheduled date, appending the time only when one was set.
     */
    private function formatAppointment(ServiceSchedule $serviceSchedule): string
    {
        if (blank($serviceSchedule->scheduled_date)) {
            return '';
        }

        $when = Carbon::parse($serviceSchedule->scheduled_date);

        if ($when->format('H:i') === '00:00') {
            return $when->format('l, F j, Y');
        }

        return $when->format('l, F j, Y').' at '.$when->format('g:i A');
    }

    /**
     * The WOC's own number if the work order has one, else the maintenance line,
     * else the general Twilio number.
     */
    private function fromNumber(WorkOrder $workOrder): ?string
    {
        return $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_from')
            ?: config('services.twilio.from');
    }

    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }
}
