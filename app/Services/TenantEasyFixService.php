<?php

namespace App\Services;

use App\Ai\Agents\TenantEasyFixJudgeAgent;
use App\Ai\TenantEasyFixCriteria;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Decides at intake whether a new work order is a tenant easy fix (a small
 * repair the handbook says the tenant handles, texted a how-to video instead
 * of the generic confirmation), and records that verdict once on the work
 * order so the tenant text, the owner text, the intake email and the board
 * all agree.
 *
 * The keywords (App\Ai\TenantEasyFixCriteria over the description, type and
 * category) shortlist the handbook items a request might be, and the AI judge
 * confirms one or rejects them all (Earl, 2026-10-02): a keyword hit alone no
 * longer tags a work order, and the AI can never pick an item the keywords did
 * not find. The verdict is written whether or not the messaging gate is on, so
 * it can be watched in production before anyone is texted.
 *
 * Whether the item is a non-realty appliance under the lease (a washer,
 * dryer or refrigerator provided as-is, the tenant's to repair) is not
 * decided here: the tenant still gets the handbook video, and the WOC makes
 * that call by hand from "Checking for Tenant Easy Fix", moving the work
 * order to "Waiting Tenants Decision - Non Real Property Item" and messaging
 * the tenant and owner themselves (Earl, 2026-09-23).
 */
class TenantEasyFixService
{
    public const EASY_FIX_STATUS = 'Checking for Tenant Easy Fix';

    /**
     * How many check-ins follow the easy-fix text (the manual's "follow up
     * with the tenant within 1 day"): one per weekday, then silence.
     */
    public const FOLLOW_UP_DAYS = 3;

    /**
     * The token's notification cap for an easy-fix text: the text itself plus
     * the check-ins.
     */
    public const FOLLOW_UP_MAX_NOTIFICATIONS = 1 + self::FOLLOW_UP_DAYS;

    /**
     * Service statuses a work order can sit in while the tenant is still
     * trying the fix. Anything else means a WOC has moved it on.
     */
    private const FOLLOW_UP_STATUSES = [self::EASY_FIX_STATUS, 'New'];

    /**
     * The activity log the verdicts are written to, one row per assessment
     * the keywords shortlisted; its description is the reason the board shows.
     */
    public const VERDICT_LOG = 'tenant_easy_fix';

    /**
     * judge() reasons that never reach the keywords or the AI.
     */
    private const SKIP_REASONS = ['hoa_violation', 'automated_messages_skipped', 'marked_emergency'];

    public function __construct(private PropertyWareService $propertyWare) {}

    /**
     * Whether this work order's tenant was actually sent the easy-fix how-to
     * text (the ledger row the intake sender writes), which is what makes the
     * portal token's reminders the "did the video help?" check-ins instead of
     * the generic photo reminders.
     */
    public function tenantWasTexted(WorkOrder $workOrder): bool
    {
        return Activity::query()
            ->inLog(AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'tenant_easy_fix_sms')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->exists();
    }

    /**
     * Whether the check-ins should stop: the tenant has replied since the
     * how-to text went out, a vendor has been assigned, the work order is no
     * longer open, or a WOC has moved it past "Checking for Tenant Easy Fix"
     * / "New". A photo through the portal is handled by the token's
     * completed_at, as for every reminder.
     */
    public function followUpClosed(WorkOrder $workOrder, TenantUploadToken $token): bool
    {
        if ($workOrder->status !== null && strcasecmp((string) $workOrder->status, 'Open') !== 0) {
            return true;
        }

        $workOrder->loadMissing('service_status');
        $statusName = (string) ($workOrder->service_status?->name ?? '');

        if ($statusName !== '' && ! in_array($statusName, self::FOLLOW_UP_STATUSES, true)) {
            return true;
        }

        if ($workOrder->vendors()->exists()) {
            return true;
        }

        // is_read is the direction marker on work_order_conversations: false
        // means the tenant wrote it.
        return Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->where('is_read', false)
            ->where('created_at', '>=', $token->created_at)
            ->exists();
    }

    /**
     * The work order's verdict: which item it matched and whether that item
     * can be messaged about, or null when it is not an easy fix. Assessed and
     * stored on first call; later calls (the parallel owner and email jobs,
     * the portal, the board) read the stored verdict. Log-never-throw.
     *
     * @return array{key: string, item: array<string, mixed>, sendable: bool}|null
     */
    public function assess(WorkOrder $workOrder): ?array
    {
        try {
            $key = $this->storedOrFreshKey($workOrder);

            return $this->verdictFor($key);
        } catch (\Throwable $exception) {
            Log::warning('Tenant easy-fix assessment failed; treating as not an easy fix.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The verdict for a stored key, without touching the database.
     *
     * @return array{key: string, item: array<string, mixed>, sendable: bool}|null
     */
    public function verdictFor(?string $key): ?array
    {
        $item = TenantEasyFixCriteria::item($key);

        if ($item === null) {
            return null;
        }

        return [
            'key' => $key,
            'item' => $item,
            // The easy-fix text is built around the how-to video.
            'sendable' => TenantEasyFixCriteria::isSendable($key),
        ];
    }

    /**
     * Whether the easy-fix texts (the how-to and its check-ins) are on.
     */
    public function enabled(): bool
    {
        return (bool) config('services.twilio.tenant_easy_fix_sms');
    }

    /**
     * Whether the tenant will actually be told (so the owner text can say
     * "we have sent the tenant a how-to video" truthfully): the gate is on,
     * the verdict is one we can message about, tenant automation is not
     * muted on this work order, and at least one tenant is reachable by text
     * or email through the same intake channels.
     *
     * @param  array{key: string, item: array<string, mixed>, sendable: bool}|null  $verdict
     */
    public function tenantWillBeTold(WorkOrder $workOrder, ?array $verdict): bool
    {
        if ($verdict === null || ! $verdict['sendable'] || ! $this->enabled()) {
            return false;
        }

        // A work order our team entered keeps the "created by our team"
        // wording on every channel, so the tenant is not told either.
        if ($workOrder->isStaffCreated() || $workOrder->automationPausedFor('tenant')) {
            return false;
        }

        $workOrder->loadMissing(['requested_by', 'tenants']);

        $byText = config('services.twilio.tenant_intake_sms')
            && $workOrder->tenantIntakeRecipients(fn (Tenants $tenant): bool => $workOrder->normalizedTenantPhone($tenant) !== null)->isNotEmpty();

        $byEmail = config('services.work_order.tenant_intake_email')
            && $workOrder->tenantIntakeRecipients(fn (Tenants $tenant): bool => $this->deliverableEmail($tenant->email))->isNotEmpty();

        return $byText || $byEmail;
    }

    /**
     * The easy-fix portal token for this work order, created on first use
     * as already-notified-once: the intake text is the first notification,
     * so the scheduled reminders continue from it instead of sending a
     * second "here is your link" text, and a photo the tenant uploads
     * through it stops them.
     */
    public function openEasyFixToken(WorkOrder $workOrder): TenantUploadToken
    {
        return TenantUploadToken::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id, 'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX],
            [
                'token' => TenantUploadToken::generateUniqueToken(),
                'notified_count' => 1,
                'last_notified_at' => now(),
            ],
        );
    }

    /**
     * Move the work order to "Checking for Tenant Easy Fix". PropertyWare is
     * the source of truth for service status (the importers copy it back
     * every few minutes), so the status is pushed there first and written
     * locally only when PropertyWare accepted it; a local-only work order
     * (no PropertyWare id) is left alone. Log-never-throw, no task
     * regeneration - the same shape as the HOA intake.
     */
    public function applyStatus(WorkOrder $workOrder): void
    {
        try {
            if (blank($workOrder->propertyware_id)) {
                return;
            }

            $name = self::EASY_FIX_STATUS;
            $status = ServiceStatus::query()->where('name', $name)->first();

            if ($status === null) {
                Log::warning('Tenant easy-fix service status not found; work order status left unchanged.', [
                    'work_order_id' => $workOrder->id,
                    'status' => $name,
                ]);

                return;
            }

            if ((int) $workOrder->service_status_id === (int) $status->id) {
                return;
            }

            if (! $this->propertyWare->updateServiceStatus($workOrder, $status)) {
                Log::warning('PropertyWare did not accept the tenant easy-fix service status; local status left unchanged.', [
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'status' => $name,
                ]);

                return;
            }

            $workOrder->update(['service_status_id' => $status->id]);
        } catch (\Throwable $exception) {
            Log::warning('Applying the tenant easy-fix service status failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The handbook's "Tenant can try first" wording as a sentence: the sheet
     * writes them as fragments ("Check/reset GFCI"), so add the full stop
     * the text and email need.
     */
    public static function tipSentence(string $tip): string
    {
        $tip = trim($tip);

        if ($tip === '' || preg_match('/[.!?]$/', $tip)) {
            return $tip;
        }

        return $tip.'.';
    }

    /**
     * The text the criteria are judged against: what the tenant wrote plus
     * PropertyWare's type and category.
     */
    public static function textOf(WorkOrder $workOrder): string
    {
        return Str::lower(implode("\n", array_filter([
            (string) $workOrder->description,
            (string) $workOrder->type,
            (string) $workOrder->category,
        ])));
    }

    /**
     * A fresh, unstored judgement of the work order: the item key or null,
     * plus why. Writes nothing - used by the audit command as well as by
     * assess(). $withAi false is the keywords alone (no AI call), for the
     * classification heuristic's preview of a work order not yet assessed.
     *
     * @return array{key: ?string, reason: string}
     */
    public function judge(WorkOrder $workOrder, bool $withAi = true): array
    {
        if ($workOrder->isHoaViolation()) {
            return ['key' => null, 'reason' => 'hoa_violation'];
        }

        if ($workOrder->skipsAutomatedMessages()) {
            return ['key' => null, 'reason' => 'automated_messages_skipped'];
        }

        // Not cast on the model: 1/"1"/true all mean flagged.
        if ($workOrder->is_emergency !== null && (bool) $workOrder->is_emergency) {
            return ['key' => null, 'reason' => 'marked_emergency'];
        }

        $candidates = TenantEasyFixCriteria::candidates(self::textOf($workOrder), $workOrder->category);

        if ($candidates === []) {
            return ['key' => null, 'reason' => 'no_match'];
        }

        $matched = 'matched:'.implode(', ', $candidates[0]['matched']);

        if (! $withAi || ! $this->aiJudgeEnabled()) {
            return ['key' => $candidates[0]['key'], 'reason' => $matched];
        }

        return $this->aiJudge($workOrder, $candidates);
    }

    /**
     * Whether the AI confirms the keyword shortlist: switched on and a
     * provider configured. Without one the keywords decide alone, exactly as
     * before the judge existed.
     */
    public function aiJudgeEnabled(): bool
    {
        return (bool) config('services.ai.easy_fix_judge') && AiSettings::ready();
    }

    /**
     * Ask the AI which shortlisted item the request really is, if any. Only a
     * shortlisted key at or above the confidence bar tags the work order; a
     * rejection, an unknown key, low confidence or an AI failure all mean
     * "not an easy fix", because a wrong yes texts the tenant a how-to for a
     * repair that needs a vendor.
     *
     * @param  array<int, array{key: string, matched: array<int, string>}>  $candidates
     * @return array{key: ?string, reason: string}
     */
    private function aiJudge(WorkOrder $workOrder, array $candidates): array
    {
        $shortlist = array_column($candidates, 'key');

        try {
            $response = (new TenantEasyFixJudgeAgent)->prompt($this->judgePrompt($workOrder, $shortlist), timeout: 30);
        } catch (\Throwable $exception) {
            Log::warning('Tenant easy-fix AI judge failed; treating as not an easy fix.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return ['key' => null, 'reason' => 'ai_unavailable'];
        }

        $key = TenantEasyFixCriteria::normalizeKey(data_get($response, 'easy_fix_key'));
        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $why = Str::limit(trim((string) data_get($response, 'reason', '')), 200);
        $minimum = (int) config('services.ai.easy_fix_min_confidence', 70);

        if ($key === null || ! in_array($key, $shortlist, true)) {
            return ['key' => null, 'reason' => 'ai_rejected: '.$why];
        }

        if ($confidence < $minimum) {
            return ['key' => null, 'reason' => "ai_unsure ({$confidence}%): ".$why];
        }

        return ['key' => $key, 'reason' => "ai_confirmed ({$confidence}%): ".$why];
    }

    /**
     * @param  array<int, string>  $shortlist
     */
    private function judgePrompt(WorkOrder $workOrder, array $shortlist): string
    {
        return implode("\n", [
            'WORK ORDER',
            'Description: '.Str::limit(trim((string) $workOrder->description), 1000),
            'Type: '.($workOrder->type ?: 'n/a'),
            'Category: '.($workOrder->category ?: 'n/a'),
            '',
            'SHORTLIST (handbook items the keywords matched):',
            ...array_map(fn (string $key): string => '- '.TenantEasyFixCriteria::describeForJudge($key), $shortlist),
        ]);
    }

    /**
     * The stored key when the work order has been assessed; otherwise judge
     * it now and claim the verdict atomically, so the parallel intake jobs
     * (tenant text, owner text, tenant email) can never disagree - whichever
     * runs first writes, the others read it back. Written with a plain query
     * so no model hook or import diff sees it.
     */
    private function storedOrFreshKey(WorkOrder $workOrder): ?string
    {
        $stored = DB::table('work_orders')
            ->where('id', $workOrder->id)
            ->first(['easy_fix_key', 'easy_fix_assessed_at']);

        if ($stored === null) {
            return null;
        }

        if ($stored->easy_fix_assessed_at !== null) {
            return $stored->easy_fix_key;
        }

        $judgement = $this->judge($workOrder);
        $key = $judgement['key'];

        $claimed = DB::table('work_orders')
            ->where('id', $workOrder->id)
            ->whereNull('easy_fix_assessed_at')
            ->update(['easy_fix_key' => $key, 'easy_fix_assessed_at' => now()]);

        if ($claimed === 0) {
            return DB::table('work_orders')->where('id', $workOrder->id)->value('easy_fix_key');
        }

        $workOrder->setRawAttributes(['easy_fix_key' => $key] + $workOrder->getAttributes(), true);

        // Only the verdicts the keywords shortlisted are worth a row: that is
        // where the board shows a coordinator why the AI said yes or no.
        if ($judgement['reason'] !== 'no_match' && ! in_array($judgement['reason'], self::SKIP_REASONS, true)) {
            $this->recordVerdict($workOrder, $judgement);
        }

        return $key;
    }

    /**
     * @param  array{key: ?string, reason: string}  $judgement
     */
    private function recordVerdict(WorkOrder $workOrder, array $judgement): void
    {
        try {
            activity(self::VERDICT_LOG)
                ->performedOn($workOrder)
                ->event($judgement['key'] !== null ? 'easy_fix_tagged' : 'easy_fix_rejected')
                ->withProperties(['easy_fix_key' => $judgement['key']])
                ->log(Str::limit($judgement['reason'], 250));
        } catch (\Throwable $exception) {
            Log::warning('Recording the tenant easy-fix verdict failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Mirrors TenantWorkOrderEmailSender: a real address, not the importer's
     * synthetic placeholder.
     */
    private function deliverableEmail(?string $email): bool
    {
        $email = trim((string) $email);

        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! str_ends_with($email, '@texasrenter.com');
    }
}
