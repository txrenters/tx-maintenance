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
     * Tell the tenant their work order is in: that we have received their
     * service request, or, when our team entered the work order in
     * PropertyWare (Source anything but Tenant Portal or Website), that a work
     * order has been created for their home by our team.
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

        // A turnover/re-key/vacant home has no tenant awaiting repairs, and a
        // company-ordered refresh cleaning was never requested by the tenant —
        // confirming "we received your service request" only confuses them.
        // Checked here rather than in notify() so the one-shot stamp stays
        // clear and a work order later re-categorized can still notify.
        if ($workOrder->skipsAutomatedMessages()) {
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

        $staffCreated = $workOrder->isStaffCreated();

        $message = TenantMessageFormatter::compose(
            $staffCreated ? $this->createdMessage($workOrder) : $this->confirmationMessage($workOrder),
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

        $ledgerExtra = ['conversation_id' => $conversation->id];

        if ($staffCreated) {
            $ledgerExtra['variant'] = 'staff_created';
        }

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_service_request_sms',
            $tenantNumber,
            $workOrder,
            $message,
            $ledgerExtra,
        );
    }

    /**
     * The confirmation wording, matching the acknowledgement tenants already
     * receive today so the message reads as familiar rather than as a second,
     * different system talking to them. Editable from the Automated Messages
     * page via the AutomatedMessageTemplates registry.
     */
    private function confirmationMessage(WorkOrder $workOrder): string
    {
        $address = $workOrder->propertyAddress();

        return AutomatedMessageTemplates::text('tenant_service_request_sms', [
            'greeting' => $this->greeting($workOrder),
            'property' => $address !== null ? " for {$address}" : '',
        ]);
    }

    /**
     * The "created by our team" wording for a work order staff entered in
     * PropertyWare: the WOC ticket's text, with the description inline and
     * "your home" standing in for an unknown address.
     */
    private function createdMessage(WorkOrder $workOrder): string
    {
        return AutomatedMessageTemplates::text('tenant_work_order_created_sms', [
            'greeting' => $this->greeting($workOrder),
            'work_order_no' => (string) ($workOrder->work_order_no ?? $workOrder->id),
            'property' => $workOrder->propertyAddress() ?? 'your home',
            'description_line' => AutomatedMessageTemplates::descriptionLine($workOrder->description),
        ]);
    }

    /**
     * "Hi <first name>," or "Hi," when the requested-by contact has no name.
     */
    private function greeting(WorkOrder $workOrder): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));

        return $name !== '' ? "Hi {$name}," : 'Hi,';
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
