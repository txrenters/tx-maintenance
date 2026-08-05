<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantAppointmentNotificationService
{
    public function __construct(private TenantPortalLinkService $portalLinks) {}

    /**
     * Tell the tenant their service appointment has been set.
     *
     * Posts the update into the tenant<->WOC conversation thread and texts the
     * tenant the date, the vendor, and their no-login portal link. Unlike the
     * owner notification this fires whoever set the schedule — a tenant always
     * needs to know someone is coming to their home. Fired at most once per
     * schedule, and wrapped so a failure is logged but never breaks schedule
     * creation.
     */
    public function notify(ServiceSchedule $serviceSchedule): void
    {
        if (! config('services.twilio.tenant_schedule_sms')) {
            return;
        }

        // A cancelled schedule is not an appointment worth announcing.
        if ($serviceSchedule->status === 'cancelled') {
            return;
        }

        // A WOC can mute this work order's tenant automation from the tenant
        // conversation tab; manual sends are unaffected.
        if ($serviceSchedule->work_order?->automationPausedFor('tenant')) {
            return;
        }

        // Claim this schedule atomically so a retry can never text the tenant
        // twice for the same appointment. A genuine reschedule clears the stamp
        // first (see ServiceScheduleController::update), so it re-notifies.
        $claimed = DB::table('service_schedules')
            ->where('id', $serviceSchedule->id)
            ->whereNull('tenant_notified_at')
            ->update(['tenant_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $this->send($serviceSchedule);
        } catch (\Throwable $exception) {
            Log::error('Tenant appointment notification failed to send.', [
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
            'work_order.requested_by',
            'work_order.building',
            'work_order.woc.wocNumber.twilioPhoneNumber',
        ]);

        $workOrder = $serviceSchedule->work_order;

        if (! $workOrder instanceof WorkOrder) {
            return;
        }

        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);
        $fromNumber = $this->fromNumber($workOrder);

        $message = TenantMessageFormatter::compose(
            $this->message($serviceSchedule, $workOrder),
            $workOrder->work_order_no ?? $workOrder->id,
            $this->portalLinks->link($workOrder),
        );

        // Log the update in the tenant thread even when there is nothing to
        // text, so the coordinator can see the appointment went out.
        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => false,
        ]);

        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, null, $conversation->id);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_appointment_sms',
            $tenantNumber,
            $workOrder,
            $message,
            ['service_schedule_id' => $serviceSchedule->id, 'conversation_id' => $conversation->id],
        );
    }

    /**
     * The tenant-facing appointment message: when the vendor is coming, who
     * they are, and a nudge to tell us if the time does not work.
     */
    private function message(ServiceSchedule $serviceSchedule, WorkOrder $workOrder): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';

        $vendorName = trim((string) ($serviceSchedule->vendor?->name
            ?: $serviceSchedule->vendor?->user?->name
            ?: 'the assigned vendor'));

        $when = $this->formatAppointment($serviceSchedule);
        $address = $workOrder->propertyAddress();
        $property = $address !== null ? ' at '.$address : '';

        return TenantMessageFormatter::paragraphs([
            $greeting,
            "This is TexasRenters.com Maintenance. The service appointment for your home{$property} has been scheduled with {$vendorName}.",
            $when !== '' ? "Scheduled: {$when}" : null,
            'Please make sure someone 18 or older is home to let the technician in. If that time does not work for you, just reply here and we will help reschedule.',
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

    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits !== '' ? '+'.$digits : null;
    }
}
