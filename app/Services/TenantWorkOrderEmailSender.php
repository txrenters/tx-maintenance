<?php

namespace App\Services;

use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Emails the tenant a branded confirmation when their service request comes in,
 * carrying the no-login portal link so they can follow the request, message the
 * coordinator, and add photos without waiting to be asked. When our team
 * entered the work order in PropertyWare (Source anything but Tenant Portal or
 * Website) the same email says a work order has been created for their home by
 * our team, matching the text they get.
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
        private TenantEasyFixService $easyFix,
    ) {}

    /**
     * Send the intake confirmation. Log-never-throw: intake must not fail
     * because an email could not go out.
     *
     * @param  bool  $force  a WOC pressed "send anyway" on the tenant tab, so
     *                       send even though this work order reads as muted
     */
    public function sendIntakeConfirmation(WorkOrder $workOrder, bool $force = false): bool
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
        if (! $force && $workOrder->skipsAutomatedMessages()) {
            return false;
        }

        try {
            $workOrder->loadMissing(['requested_by', 'tenants', 'building', 'woc']);

            // The requester when they are the tenant, else the lease roster
            // (see WorkOrder::tenantIntakeRecipients) — the same people the
            // SMS twin texts. One email per address.
            $recipients = $workOrder
                ->tenantIntakeRecipients(fn (Tenants $tenant): bool => $this->deliverableEmail($tenant->email) !== null)
                ->unique(fn (Tenants $tenant): string => strtolower((string) $this->deliverableEmail($tenant->email)))
                ->values();

            if ($recipients->isEmpty()) {
                return false;
            }

            if ($this->alreadySent($workOrder)) {
                return false;
            }

            $staffCreated = $workOrder->isStaffCreated();
            $requester = $workOrder->requested_by;
            $reference = $workOrder->work_order_no ?? $workOrder->id;

            // The email twin of the easy-fix text: the handbook's how-to
            // video instead of "we received your request". Same verdict the
            // text uses, same gate.
            $verdict = $this->easyFix->assess($workOrder);
            $easyFix = ! $staffCreated && $verdict !== null && $verdict['sendable'] && $this->easyFix->enabled()
                ? [
                    'key' => $verdict['key'],
                    'label' => (string) $verdict['item']['label'],
                    'video_url' => $verdict['item']['video_url'] ?? null,
                    'tip' => TenantEasyFixService::tipSentence((string) ($verdict['item']['tip'] ?? '')),
                ]
                : null;

            $subject = match (true) {
                $staffCreated => 'A work order has been created — Work Order #',
                $easyFix !== null => 'A quick fix for your '.$easyFix['label'].' — Work Order #',
                default => 'We received your service request — Work Order #',
            }.$reference;

            $portalLink = $easyFix !== null
                ? $this->portalLinks->urlFor($this->easyFix->openEasyFixToken($workOrder))
                : $this->portalLinks->link($workOrder);

            foreach ($recipients as $tenant) {
                $to = (string) $this->deliverableEmail($tenant->email);

                $html = View::make('emails.tenant-work-order-intake', [
                    'workOrder' => $workOrder,
                    'staffCreated' => $staffCreated,
                    'easyFix' => $easyFix,
                    'tenantName' => trim((string) ($tenant->first_name ?? '')),
                    'property' => $workOrder->propertyAddress() ?: $workOrder->building?->name,
                    'coordinator' => $workOrder->woc?->name,
                    'portalLink' => $portalLink,
                ])->render();

                $this->tenantEmails->send(
                    tenant: $tenant,
                    job: null,
                    to: $to,
                    subject: $subject,
                    html: $html,
                    metadata: ['work_order_id' => $workOrder->id, 'type' => 'work_order_intake'],
                    // A rendered blade carries inline styles the sanitizer would
                    // strip, so it is handed over as trusted template HTML.
                    trustedHtml: true,
                );

                $ledgerExtra = ['subject' => $subject, 'tenant_id' => $tenant->id];

                if ($staffCreated) {
                    $ledgerExtra['variant'] = 'staff_created';
                } elseif ($easyFix !== null) {
                    $ledgerExtra['variant'] = 'easy_fix';
                    $ledgerExtra['easy_fix_key'] = $easyFix['key'];
                }

                // Emailed from the lease roster because the Requested By
                // contact was not the tenant, or had no usable address.
                if ($requester === null || ! $tenant->is($requester)) {
                    $ledgerExtra['recipient_source'] = 'lease_roster';
                }

                AutomatedMessageLogService::log(
                    AutomatedMessageLogService::CHANNEL_EMAIL,
                    'tenant',
                    'tenant_intake_email',
                    $to,
                    $workOrder,
                    extra: $ledgerExtra,
                );
            }

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
