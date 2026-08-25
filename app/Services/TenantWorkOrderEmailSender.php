<?php

namespace App\Services;

use App\Models\TenantEmailNotification;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Emails the tenant a branded confirmation when their service request comes in,
 * carrying the no-login portal link so they can follow the request, message the
 * coordinator, and add photos without waiting to be asked.
 *
 * Routed through TenantJobberEmailSender so the send is recorded as a
 * TenantEmailNotification with a TNT-{tenantId} correlation tag, which lets
 * `tenant-emails:sync-replies` thread the tenant's reply back to us.
 */
class TenantWorkOrderEmailSender
{
    public function __construct(
        private TenantJobberEmailSender $tenantEmails,
        private TenantPortalLinkService $portalLinks,
    ) {}

    /**
     * Send the intake confirmation. Log-never-throw: intake must not fail
     * because an email could not go out.
     */
    public function sendIntakeConfirmation(WorkOrder $workOrder): bool
    {
        if (! config('services.work_order.tenant_intake_email')) {
            return false;
        }

        // A WOC can mute this work order's tenant automation from the tenant
        // conversation tab; manual sends are unaffected.
        if ($workOrder->automationPausedFor('tenant')) {
            return false;
        }

        // Turnover/re-key/vacant homes and company-ordered refresh cleanings
        // are opted out; mirrors the SMS twin in
        // TenantServiceRequestNotificationService.
        if ($workOrder->skipsAutomatedMessages()) {
            return false;
        }

        try {
            $workOrder->loadMissing(['requested_by', 'building', 'woc']);

            $tenant = $workOrder->requested_by;
            $to = $this->deliverableEmail($tenant?->email);

            if (! $tenant || $to === null) {
                return false;
            }

            if ($this->alreadySent($workOrder)) {
                return false;
            }

            $html = View::make('emails.tenant-work-order-intake', [
                'workOrder' => $workOrder,
                'tenantName' => trim((string) ($tenant->first_name ?? '')),
                'property' => $workOrder->propertyAddress() ?: $workOrder->building?->name,
                'coordinator' => $workOrder->woc?->name,
                'portalLink' => $this->portalLinks->link($workOrder),
            ])->render();

            $this->tenantEmails->send(
                tenant: $tenant,
                job: null,
                to: $to,
                subject: 'We received your service request — Work Order #'.($workOrder->work_order_no ?? $workOrder->id),
                html: $html,
                metadata: ['work_order_id' => $workOrder->id, 'type' => 'work_order_intake'],
                // A rendered blade carries inline styles the sanitizer would
                // strip, so it is handed over as trusted template HTML.
                trustedHtml: true,
            );

            AutomatedMessageLogService::log(
                AutomatedMessageLogService::CHANNEL_EMAIL,
                'tenant',
                'tenant_intake_email',
                $to,
                $workOrder,
                extra: [
                    'subject' => 'We received your service request — Work Order #'.($workOrder->work_order_no ?? $workOrder->id),
                ],
            );

            return true;
        } catch (\Throwable $exception) {
            Log::error('Tenant work order intake email failed to send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether this work order's intake email already went out. Unlike the SMS
     * twin there is no one-shot stamp column, so the sent-mail record is the
     * guard: intake is re-run when a lease-less work order gains its lease
     * (WorkOrderLeaseService), and the tenant must not get the email twice.
     */
    private function alreadySent(WorkOrder $workOrder): bool
    {
        return TenantEmailNotification::query()
            ->where('metadata->type', 'work_order_intake')
            ->where('metadata->work_order_id', $workOrder->id)
            ->exists();
    }

    /**
     * An address we can actually deliver to. The PropertyWare importer writes a
     * synthetic @texasrenter.com placeholder when a tenant has no address on
     * file, which must never be emailed.
     */
    private function deliverableEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (str_ends_with($email, '@texasrenter.com')) {
            return null;
        }

        return $email;
    }
}
