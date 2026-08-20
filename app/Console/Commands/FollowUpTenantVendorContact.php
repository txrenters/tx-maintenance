<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\TenantMessageFormatter;
use App\Services\TenantPortalLinkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpTenantVendorContact extends Command
{
    protected $signature = 'tenants:followup-vendor-contact';

    protected $description = 'Text the tenant every day, starting the day after a vendor is assigned, asking whether the vendor has reached out, until a service schedule is set.';

    /**
     * A safety cap on how many follow-ups a single work order can generate, so a
     * vendor who simply never schedules (but has contacted the tenant) does not
     * get the tenant texted indefinitely.
     */
    public const MAX_NOTIFICATIONS = 5;

    /**
     * Runs daily. Every open work order whose vendor was assigned at least a day
     * ago, still has no service schedule, and has not yet hit the cap is texted
     * once per day.
     *
     * Three columns keep this safe:
     *  - tenant_contact_followup_excluded_at   permanent "handled / excluded"
     *    baseline — the fresh-start backfill stamps the pre-existing backlog so
     *    it is never texted.
     *  - tenant_contact_followup_last_sent_at  the once-per-day throttle.
     *  - tenant_contact_followup_count         enforces MAX_NOTIFICATIONS.
     */
    public function handle(): int
    {
        // Gated off by default so local/testing never texts a real tenant.
        if (! config('services.twilio.tenant_vendor_followup_sms')) {
            $this->info('Tenant vendor-contact follow-up is disabled (TENANT_VENDOR_FOLLOWUP_SMS_ENABLED=false).');

            return self::SUCCESS;
        }

        $startOfToday = now()->startOfDay();
        $dayAgo = now()->subDay();

        // Candidate work orders: open, not excluded, under the cap, not already
        // texted today, and with a vendor assigned at least a day ago. The
        // vendor-age check lives in SQL so we never load the whole open board.
        $workOrderIds = DB::table('work_orders')
            ->where('status', 'Open')
            ->whereNull('tenant_contact_followup_excluded_at')
            ->where('tenant_contact_followup_count', '<', self::MAX_NOTIFICATIONS)
            ->where(function ($query) use ($startOfToday) {
                $query->whereNull('tenant_contact_followup_last_sent_at')
                    ->orWhere('tenant_contact_followup_last_sent_at', '<', $startOfToday);
            })
            ->whereExists(function ($query) use ($dayAgo) {
                $query->select(DB::raw(1))
                    ->from('work_order_vendors')
                    ->whereColumn('work_order_vendors.work_order_id', 'work_orders.id')
                    ->where('work_order_vendors.created_at', '<=', $dayAgo);
            })
            ->pluck('id');

        $sent = 0;

        foreach ($workOrderIds as $workOrderId) {
            $workOrder = WorkOrder::query()->with('vendors')->find($workOrderId);

            // Once a service schedule exists the vendor has clearly reached out,
            // so there is nothing to ask.
            if (! $workOrder || $workOrder->status !== 'Open' || $workOrder->service_schedules()->exists()) {
                continue;
            }

            // "OWNER VENDOR" means the owner is handling the repair themselves,
            // and THMP (in-house) does not reach out to schedule the way a
            // third-party vendor does (THMP messages the tenant manually). In
            // both cases asking the tenant "has the vendor reached out?" makes no
            // sense, so a work order whose only vendors are these is excluded and
            // never rescanned.
            $realVendors = $workOrder->vendors->reject(
                fn ($vendor) => $vendor->isOwnerPlaceholder() || $vendor->isThmp()
            );

            if ($realVendors->isEmpty()) {
                DB::table('work_orders')
                    ->where('id', $workOrder->id)
                    ->whereNull('tenant_contact_followup_excluded_at')
                    ->update(['tenant_contact_followup_excluded_at' => now()]);

                continue;
            }

            // A real vendor exists but none has been assigned a full day yet
            // (e.g. only a fresh assignment alongside an old placeholder): wait
            // without excluding, so tomorrow's run still texts.
            if (! $this->hasAgedAssignment($realVendors, $dayAgo)) {
                continue;
            }

            // A WOC can mute this work order's tenant automation from the tenant
            // conversation tab; skip the nudge while paused (it resumes if the
            // WOC switches it back on and there is still no schedule).
            if ($workOrder->automationPausedFor('tenant')) {
                continue;
            }

            // Turnover/re-key/vacant homes and company-ordered refresh
            // cleanings are opted out of automated tenant messages. Skipped,
            // not excluded, so the nudge resumes if the categorization was a
            // mistake.
            if ($workOrder->skipsAutomatedMessages()) {
                continue;
            }

            // Claim today's nudge atomically so an overlapping run or a retry
            // never texts the same work order twice on the same day, and the cap
            // is honored under concurrency.
            $claimed = DB::table('work_orders')
                ->where('id', $workOrder->id)
                ->where('tenant_contact_followup_count', '<', self::MAX_NOTIFICATIONS)
                ->where(function ($query) use ($startOfToday) {
                    $query->whereNull('tenant_contact_followup_last_sent_at')
                        ->orWhere('tenant_contact_followup_last_sent_at', '<', $startOfToday);
                })
                ->update([
                    'tenant_contact_followup_last_sent_at' => now(),
                    'tenant_contact_followup_count' => DB::raw('tenant_contact_followup_count + 1'),
                ]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $this->followUp($workOrder);
                $sent++;
            } catch (\Throwable $exception) {
                Log::error('Tenant vendor-contact follow-up failed to send.', [
                    'work_order_id' => $workOrder->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Tenant vendor-contact follow-ups sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Whether any of the given assignments was created a full day or more ago.
     *
     * @param  Collection<int, Vendor>  $vendors
     */
    private function hasAgedAssignment(Collection $vendors, Carbon $dayAgo): bool
    {
        return $vendors->contains(function ($vendor) use ($dayAgo) {
            $assignedAt = $vendor->pivot->created_at;

            return $assignedAt !== null
                && Carbon::parse($assignedAt)->lessThanOrEqualTo($dayAgo);
        });
    }

    /**
     * Post the follow-up into the WOC<->tenant conversation thread and text the
     * tenant, mirroring the tenant portal path so the message threads with it
     * and the tenant's reply routes to the same number.
     */
    private function followUp(WorkOrder $workOrder): void
    {
        $workOrder->loadMissing(['requested_by', 'woc.wocNumber.twilioPhoneNumber']);

        $tenant = $workOrder->requested_by;
        $tenantNumber = $this->toE164($tenant?->mobile_phone ?: $tenant?->home_phone);

        // sender_number is the WOC/company number so the portal renders this as
        // a message from the coordinator.
        $fromNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $message = $this->messageFor($workOrder);

        $conversation = Conversation::create([
            'message' => $message,
            'sender_number' => $fromNumber ?: null,
            'receiver_number' => $tenantNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => true,
            'is_mms' => false,
        ]);

        // No usable numbers: the message is still logged in the tenant's portal
        // thread, but there is nothing to text.
        if (blank($tenantNumber) || blank($fromNumber)) {
            return;
        }

        SendConversationMessageJob::dispatch($tenantNumber, $fromNumber, $message, null, $conversation->id);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_vendor_contact_follow_up_sms',
            $tenantNumber,
            $workOrder,
            $message,
            ['conversation_id' => $conversation->id],
        );
    }

    /**
     * The follow-up wording, editable from the Automated Messages page via the
     * AutomatedMessageTemplates registry.
     */
    private function messageFor(WorkOrder $workOrder): string
    {
        $name = trim((string) ($workOrder->requested_by?->first_name ?? ''));
        $ref = $workOrder->work_order_no ?? $workOrder->id;

        $body = AutomatedMessageTemplates::text('tenant_vendor_contact_follow_up_sms', [
            'greeting' => $name !== '' ? "Hi {$name}, " : 'Hi, ',
            'work_order_no' => (string) $ref,
        ]);

        return TenantMessageFormatter::compose(
            $body,
            $ref,
            app(TenantPortalLinkService::class)->link($workOrder),
        );
    }

    /**
     * Normalize a US phone number to E.164 (+1XXXXXXXXXX); null if unusable.
     */
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
