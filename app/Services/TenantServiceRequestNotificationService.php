<?php

namespace App\Services;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Tenants;
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
     *
     * @param  bool  $force  a WOC pressed "send anyway" on the tenant tab, so
     *                       send even though this work order reads as muted
     *                       (most often a website request whose lease has not
     *                       been attached in PropertyWare yet)
     */
    public function notify(WorkOrder $workOrder, bool $force = false): void
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
            $this->send($workOrder, $force);
        } catch (\Throwable $exception) {
            Log::error('Tenant service-request notification failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function send(WorkOrder $workOrder, bool $force = false): void
    {
        // An HOA violation is not something the tenant asked us for, and the HOA
        // flow sends them its own texts. Confirming "we received your service
        // request" would be wrong on both counts. Never forceable: the HOA flow
        // is what should be talking to this tenant.
        if ($workOrder->isHoaViolation()) {
            return;
        }

        // A turnover/re-key/vacant home has no tenant awaiting repairs, and a
        // company-ordered refresh cleaning was never requested by the tenant —
        // confirming "we received your service request" only confuses them.
        // Checked here rather than in notify() so the one-shot stamp stays
        // clear and a work order later re-categorized can still notify.
        if (! $force && $workOrder->skipsAutomatedMessages()) {
            return;
        }

        $workOrder->loadMissing([
            'requested_by',
            'tenants',
            'building',
            'woc.wocNumber.twilioPhoneNumber',
        ]);

        // The requester when they are the tenant, else the lease roster (see
        // WorkOrder::tenantIntakeRecipients). Two tenants sharing one line get
        // a single text.
        $recipients = $workOrder
            ->tenantIntakeRecipients(fn (Tenants $tenant): bool => $workOrder->normalizedTenantPhone($tenant) !== null)
            ->unique(fn (Tenants $tenant): string => (string) $workOrder->normalizedTenantPhone($tenant))
            ->values();

        // Nobody to text. Left un-stamped so a tenant added later can still be
        // notified, and recorded so the Automated Messages page shows why the
        // tenant heard nothing instead of an empty gap (WO#44111: Requested By
        // was the technician, no phone, and the skip was invisible).
        if ($recipients->isEmpty()) {
            $this->recordNobodyToText($workOrder);

            return;
        }

        $fromNumber = $this->fromNumber($workOrder);

        // No line to send from.
        if (blank($fromNumber)) {
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
        $requester = $workOrder->requested_by;
        $portalLink = $this->portalLinks->link($workOrder);

        foreach ($recipients as $tenant) {
            $tenantNumber = (string) $workOrder->normalizedTenantPhone($tenant);

            $message = TenantMessageFormatter::compose(
                $staffCreated ? $this->createdMessage($workOrder, $tenant) : $this->confirmationMessage($workOrder, $tenant),
                $workOrder->work_order_no ?? $workOrder->id,
                $portalLink,
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

            $ledgerExtra = ['conversation_id' => $conversation->id, 'tenant_id' => $tenant->id];

            if ($staffCreated) {
                $ledgerExtra['variant'] = 'staff_created';
            }

            // Texted from the lease roster because the Requested By contact
            // was not the tenant, or could not be reached.
            if ($requester === null || ! $tenant->is($requester)) {
                $ledgerExtra['recipient_source'] = 'lease_roster';
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
    }

    /**
     * A ledger row saying why no tenant was texted, in the words the
     * Automated Messages page shows: whether PropertyWare's Requested By
     * contact is missing, is not on the lease, or has no number, and how many
     * lease tenants there were to fall back on.
     */
    private function recordNobodyToText(WorkOrder $workOrder): void
    {
        $requester = $workOrder->requested_by;
        $rosterCount = $workOrder->tenants->count();

        if ($requester === null) {
            $requesterLine = 'PropertyWare lists no Requested By contact';
        } else {
            $name = trim(($requester->first_name ?? '').' '.($requester->last_name ?? '')) ?: 'an unnamed contact';
            $requesterLine = $workOrder->requesterIsLeaseTenant()
                ? "Requested By is {$name}, who has no phone number"
                : "Requested By is {$name}, who is not on the lease";
        }

        $rosterLine = $rosterCount === 0
            ? 'no lease tenants on file'
            : "{$rosterCount} lease tenant(s) on file, none with a phone number";

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_service_request_sms',
            null,
            $workOrder,
            "Not sent: nobody to text. {$requesterLine}; {$rosterLine}.",
            ['not_texted_reason' => 'no_tenant_phone'],
        );
    }

    /**
     * The confirmation wording, matching the acknowledgement tenants already
     * receive today so the message reads as familiar rather than as a second,
     * different system talking to them. Editable from the Automated Messages
     * page via the AutomatedMessageTemplates registry.
     */
    private function confirmationMessage(WorkOrder $workOrder, Tenants $tenant): string
    {
        $address = $workOrder->propertyAddress();

        return AutomatedMessageTemplates::text('tenant_service_request_sms', [
            'greeting' => $this->greeting($tenant),
            'property' => $address !== null ? " for {$address}" : '',
        ]);
    }

    /**
     * The "created by our team" wording for a work order staff entered in
     * PropertyWare: the WOC ticket's text, with the description inline and
     * "your home" standing in for an unknown address.
     */
    private function createdMessage(WorkOrder $workOrder, Tenants $tenant): string
    {
        return AutomatedMessageTemplates::text('tenant_work_order_created_sms', [
            'greeting' => $this->greeting($tenant),
            'work_order_no' => (string) ($workOrder->work_order_no ?? $workOrder->id),
            'property' => $workOrder->propertyAddress() ?? 'your home',
            'description_line' => AutomatedMessageTemplates::descriptionLine($workOrder->description),
        ]);
    }

    /**
     * "Hi <first name>," or "Hi," when the tenant has no name on file.
     */
    private function greeting(Tenants $tenant): string
    {
        $name = trim((string) ($tenant->first_name ?? ''));

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
}
