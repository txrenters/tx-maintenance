<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerAppointmentNotificationService
{
    public function __construct(private OwnerPortalLinkService $portalLinks) {}

    /**
     * Notify every property owner on the work order that a vendor has set the
     * service appointment.
     *
     * Posts the update into the owner<->WOC conversation thread and texts each
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

        // A WOC can mute this work order's owner automation from the owner
        // conversation tab; manual sends are unaffected.
        if ($serviceSchedule->work_order?->automationPausedFor('owner')) {
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

        $fromNumber = $this->fromNumber($workOrder);
        $message = $this->message($serviceSchedule, $workOrder);

        // Every owner on the work order, any ownership percentage (0% owners
        // are usually spouses or the humans behind a phoneless LLC), one text
        // and one conversation entry each, shared numbers de-duplicated.
        $owners = $workOrder->notifiableOwners();

        // No textable owner: log the update once in the owner thread anyway, so
        // the coordinator sees the appointment update went out.
        if ($owners->isEmpty()) {
            Conversation::create([
                'message' => $message,
                'sender_number' => $fromNumber ?: null,
                'receiver_number' => null,
                'work_order_id' => $workOrder->id,
                'conversation_type' => 'owner',
                'is_read' => true,
                'is_mms' => false,
            ]);

            return;
        }

        foreach ($owners as $owner) {
            $ownerNumber = $workOrder->normalizedOwnerPhone($owner);

            // Each owner gets their own no-login portal link, which is also the
            // link the schedule follow-up reuses.
            $ownerMessage = OwnerMessageFormatter::compose(
                $message,
                $workOrder->work_order_no,
                $this->portalLinks->link($workOrder, $owner),
            );

            $conversation = Conversation::create([
                'message' => $ownerMessage,
                'sender_number' => $fromNumber ?: null,
                'receiver_number' => $ownerNumber,
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'conversation_type' => 'owner',
                'is_read' => true,
                'is_mms' => false,
            ]);

            if (blank($ownerNumber) || blank($fromNumber)) {
                continue;
            }

            SendConversationMessageJob::dispatch($ownerNumber, $fromNumber, $ownerMessage, null, $conversation->id);
        }
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
        $address = $workOrder->propertyAddress();
        $property = $address !== null ? ' at '.$address : '';

        return OwnerMessageFormatter::paragraphs([
            'Hello,',
            "The service appointment for your property{$property} has been scheduled with {$vendorName}.",
            $when !== '' ? "Scheduled: {$when}" : null,
            'If you would like to be available at the appointment time to speak with the technician directly, or to approve the work order, just let us know and we will coordinate that with you.',
            'We will keep you updated once the service has been completed.',
            'Thank you!',
        ]);
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
}
