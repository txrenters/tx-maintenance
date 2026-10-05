<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\OwnerPortalLinkService;
use App\Services\PhoneFormatter;
use App\Services\TenantPortalLinkService;
use App\Services\VendorPortalLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * One-time notice for the move to maintenance.texasrenters.com: texts every
 * tenant, owner and vendor on an OPEN work order their portal link on the new
 * domain.
 *
 * Dry run by default; --send actually texts. Each text is recorded in the
 * automated-message ledger, and a recipient already recorded there for this
 * work order is skipped, so an accidental second run never texts anyone twice.
 * Run it only after APP_URL points at the new domain -- links are built from it.
 */
class ResendPortalLinksForDomainChange extends Command
{
    public const AUTOMATION = 'portal_domain_change_sms';

    protected $signature = 'portal-links:resend-for-domain-change
        {--send : Actually send the texts (without it, only lists what would be sent)}';

    protected $description = 'One-time: text tenants, owners and vendors on open work orders their portal link on the new domain.';

    /**
     * "{work_order_id}|{audience}|{recipient}" for every notice already sent.
     *
     * @var array<string, true>
     */
    private array $alreadySent = [];

    private int $sent = 0;

    private int $skipped = 0;

    public function __construct(
        private TenantPortalLinkService $tenantLinks,
        private OwnerPortalLinkService $ownerLinks,
        private VendorPortalLinkService $vendorLinks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $sampleLink = route('tenant.portal.show', 'example');

        if ($send && ! str_starts_with($sampleLink, 'https://')) {
            $this->error("Refusing to send: links would be built as {$sampleLink}. Set APP_URL to the new https domain first.");

            return self::FAILURE;
        }

        $this->info($send ? 'Sending portal-link notices.' : 'Dry run: nothing will be sent. Re-run with --send to text.');
        $this->line("Links look like: {$sampleLink}");

        $this->alreadySent = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', self::AUTOMATION)
            ->get()
            ->mapWithKeys(fn (Activity $activity): array => [
                $activity->getExtraProperty('work_order_id').'|'.$activity->getExtraProperty('audience').'|'.$activity->getExtraProperty('recipient') => true,
            ])
            ->all();

        WorkOrder::query()
            ->where('status', 'Open')
            ->with(['requested_by', 'tenants', 'owners', 'vendors.user', 'woc.wocNumber.twilioPhoneNumber'])
            ->orderBy('id')
            ->chunk(100, function ($workOrders) use ($send): void {
                foreach ($workOrders as $workOrder) {
                    $this->notifyWorkOrder($workOrder, $send);
                }
            });

        $this->info(($send ? 'Sent' : 'Would send').": {$this->sent}. Skipped (already sent, muted or no link): {$this->skipped}.");

        return self::SUCCESS;
    }

    private function notifyWorkOrder(WorkOrder $workOrder, bool $send): void
    {
        $fromNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        if (! $workOrder->automationPausedFor('tenant')) {
            $tenants = $workOrder
                ->tenantIntakeRecipients(fn (Tenants $tenant): bool => $workOrder->normalizedTenantPhone($tenant) !== null)
                ->unique(fn (Tenants $tenant): string => (string) $workOrder->normalizedTenantPhone($tenant));

            $link = $tenants->isNotEmpty() ? $this->tenantLinks->link($workOrder) : null;

            foreach ($tenants as $tenant) {
                $this->notify($workOrder, 'tenant', (string) $workOrder->normalizedTenantPhone($tenant), $fromNumber, $link, ['tenant_id' => $tenant->id], $send);
            }
        }

        if (! $workOrder->automationPausedFor('owner')) {
            foreach ($workOrder->notifiableOwners() as $owner) {
                /** @var Owner $owner */
                $this->notify($workOrder, 'owner', (string) $workOrder->normalizedOwnerPhone($owner), $fromNumber, $this->ownerLinks->link($workOrder, $owner), ['owner_id' => $owner->id], $send);
            }
        }

        if (! $workOrder->automationPausedFor('vendor')) {
            foreach ($workOrder->vendors as $vendor) {
                /** @var Vendor $vendor */
                $vendorNumber = PhoneFormatter::e164($vendor->phone);

                if ($vendor->isOwnerPlaceholder() || $vendorNumber === null) {
                    continue;
                }

                $this->notify($workOrder, 'vendor', $vendorNumber, $fromNumber, $this->vendorLinks->link($workOrder, $vendor), ['vendor_id' => $vendor->id], $send);
            }
        }
    }

    /**
     * @param  'tenant'|'owner'|'vendor'  $audience
     * @param  array<string, int>  $partyIds  owner_id / vendor_id / tenant_id for the thread and ledger
     */
    private function notify(WorkOrder $workOrder, string $audience, string $toNumber, ?string $fromNumber, ?string $link, array $partyIds, bool $send): void
    {
        $key = $workOrder->id.'|'.$audience.'|'.$toNumber;

        $skipReason = match (true) {
            isset($this->alreadySent[$key]) => 'already sent',
            $link === null => 'no portal link',
            blank($fromNumber) => 'no sender number',
            default => null,
        };

        if ($skipReason !== null) {
            $this->skipped++;
            $this->line("<comment>skip</comment> WO #{$workOrder->work_order_no}  {$audience}  {$toNumber}  ({$skipReason})", verbosity: 'v');

            return;
        }

        $message = $this->messageFor($workOrder, $link);
        $this->line("WO #{$workOrder->work_order_no}  {$audience}  {$toNumber}  {$link}");
        $this->sent++;

        if (! $send) {
            return;
        }

        try {
            $conversation = Conversation::create([
                'message' => $message,
                'sender_number' => $fromNumber,
                'receiver_number' => $toNumber,
                'work_order_id' => $workOrder->id,
                'owner_id' => $partyIds['owner_id'] ?? null,
                'vendor_id' => $partyIds['vendor_id'] ?? null,
                'conversation_type' => $audience,
                'is_read' => true,
                'is_mms' => false,
            ]);

            SendConversationMessageJob::dispatch($toNumber, $fromNumber, $message, null, $conversation->id);

            AutomatedMessageLogService::log(
                AutomatedMessageLogService::CHANNEL_SMS,
                $audience,
                self::AUTOMATION,
                $toNumber,
                $workOrder,
                $message,
                $partyIds + ['conversation_id' => $conversation->id],
            );

            $this->alreadySent[$key] = true;
        } catch (\Throwable $exception) {
            $this->sent--;
            $this->skipped++;
            Log::error('Portal domain-change notice failed to send.', [
                'work_order_id' => $workOrder->id,
                'audience' => $audience,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function messageFor(WorkOrder $workOrder, string $link): string
    {
        $property = $workOrder->propertyAddress();

        return 'Hello! TexasRenters Maintenance is updating our domain, and this affects your portal links. '
            ."Here is your new portal link for Work Order #{$workOrder->work_order_no}"
            .($property ? " ({$property})" : '')
            .":\n{$link}\n\n- TX Maintenance Team";
    }
}
