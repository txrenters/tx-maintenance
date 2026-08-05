<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantServiceRequestNotificationService
{
    public function __construct(private TenantPortalLinkService $portalLinks) {}

    /**
     * Tell the tenant we have received their service request.
     *
     * The SMS counterpart of the intake email: same moment, same portal link,
     * for the tenants who read texts but not email. Posts into the
     * tenant<->WOC conversation thread and texts the tenant.
     *
     * Fired at most once per work order, and wrapped so a failure is logged but
     * never breaks intake.
     */
    public function notify(WorkOrder $workOrder): void
    {
        if (! config('services.twilio.tenant_intake_sms')) {
            return;
        }

        // A WOC can mute this work order's tenant automation from the tenant
        // conversation tab; manual sends are unaffected.
        if ($workOrder->automationPausedFor('tenant')) {
            return;
        }

        try {
            $this->send($workOrder);
        } catch (\Throwable $exception) {
            Log::error('Tenant service-request notification failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WorkOrder $workOrder): void
    {
        // An HOA violation is not something the tenant asked us for, and the HOA
        // flow sends them its own texts. Confirming "we received your service
        // request" would be wrong on both counts.
        if ($workOrder->isHoaViolation()) {
            return;
        }

        $workOrder->loadMissing([
            'requested_by',
            'building',
            'woc.wocNumber.twilioPhoneNumber',
        ]);

        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);
        $fromNumber = $this->fromNumber($workOrder);

        // Nothing to text: no tenant number, or no line to send from.
        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        // Claim this work order atomically so a redelivery can never text the
        // tenant twice for the same request.
        $claimed = DB::table('work_orders')
            ->where('id', $workOrder->id)
            ->whereNull('tenant_service_request_notified_at')
            ->update(['tenant_service_request_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $message = TenantMessageFormatter::compose(
            $this->confirmationMessage($workOrder),
            $workOrder->work_order_no ?? $workOrder->id,
            $this->portalLinks->link($workOrder),
        );

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => false,
        ]);

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, null, $conversation->id);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_service_request_sms',
            $tenantNumber,
            $workOrder,
            $message,
            ['conversation_id' => $conversation->id],
        );
    }

    /**
     * The confirmation wording, matching the acknowledgement tenants already
     * receive today so the message reads as familiar rather than as a second,
     * different system talking to them.
     */
    private function confirmationMessage(WorkOrder $workOrder): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';

        $address = $workOrder->propertyAddress();
        $property = $address !== null ? " for {$address}" : '';

        return TenantMessageFormatter::paragraphs([
            $greeting,
            "This is TexasRenters.com Maintenance. We wanted to let you know we have received your service request{$property}.",
            'Once we review the details with our maintenance team and, if needed, the owner, we will provide you with further information on how we will proceed with any necessary repairs.',
            'Photos of the issue help us get the right person out the first time, so please add them if you can.',
        ]);
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
