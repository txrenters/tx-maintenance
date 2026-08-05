<?php

namespace App\Services;

use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

/**
 * Emails the tenant and the property owner that an HOA violation has been
 * corrected, with a signed link to the before/after photos. This is the first
 * owner-facing notification email in the HOA flow; sent once per token
 * (idempotent via confirmation_sent_at).
 */
class HoaViolationConfirmationSender
{
    public function __construct(
        private readonly MicrosoftGraphMailService $graph,
        private readonly TenantPortalLinkService $portalLinks,
    ) {}

    public function send(TenantUploadToken $token): bool
    {
        $workOrder = $token->work_order;

        if (! $workOrder) {
            return false;
        }

        $workOrder->loadMissing(['requested_by', 'owners', 'building']);

        $recipients = $this->recipients($workOrder);

        if ($recipients === []) {
            Log::info('HOA confirmation email skipped: no tenant or owner email.', [
                'work_order_id' => $workOrder->id,
            ]);

            // Nothing to send, but treat as handled so it is not retried forever.
            return true;
        }

        $galleryUrl = URL::signedRoute('hoa.photos.show', ['workOrder' => $workOrder->id]);

        $html = View::make('emails.hoa-violation-corrected', [
            'workOrder' => $workOrder,
            'property' => $workOrder->building?->name,
            'galleryUrl' => $galleryUrl,
            'portalLink' => $this->portalLinks->link($workOrder),
        ])->render();

        $mailbox = (string) config('services.microsoft.job_reminder_mailbox');
        $subject = 'HOA Violation Corrected — Work Order #'.($workOrder->work_order_no ?? $workOrder->id);

        $to = array_shift($recipients);

        $this->graph->sendMail($to, $recipients, $subject, $html, mailbox: $mailbox);

        $this->logSent($workOrder, array_merge([$to], $recipients), $subject, $token);

        return true;
    }

    /**
     * Ledger one row per recipient, telling tenant and owner addresses apart so
     * the IT Tools automated-messages log filters them correctly.
     *
     * @param  array<int, string>  $recipients
     */
    private function logSent(WorkOrder $workOrder, array $recipients, string $subject, TenantUploadToken $token): void
    {
        $tenantEmail = (string) $workOrder->requested_by?->email;

        foreach ($recipients as $email) {
            $isTenant = $tenantEmail !== '' && strcasecmp($email, $tenantEmail) === 0;

            AutomatedMessageLogService::log(
                AutomatedMessageLogService::CHANNEL_EMAIL,
                $isTenant ? 'tenant' : 'owner',
                $isTenant ? 'tenant_hoa_violation_confirmation_email' : 'owner_hoa_violation_confirmation_email',
                $email,
                $workOrder,
                extra: ['subject' => $subject, 'tenant_upload_token_id' => $token->id],
            );
        }
    }

    /**
     * Tenant first, then every owner with an email, de-duplicated.
     *
     * @return array<int, string>
     */
    private function recipients(WorkOrder $workOrder): array
    {
        $emails = [];

        $tenantEmail = $workOrder->requested_by?->email;

        if (filled($tenantEmail) && ! str_ends_with((string) $tenantEmail, '@texasrenter.com')) {
            $emails[] = $tenantEmail;
        }

        foreach ($workOrder->owners as $owner) {
            if (filled($owner->email) && ! str_ends_with((string) $owner->email, '@texasrenter.com')) {
                $emails[] = $owner->email;
            }
        }

        return array_values(array_unique($emails));
    }
}
