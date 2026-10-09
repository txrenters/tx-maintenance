<?php

namespace App\Services;

use App\Ai\Agents\TenantEasyFixReplyJudgeAgent;
use App\Ai\TenantEasyFixCriteria;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The "Ready to close" label on the Tenant Easy Fix board (Earl, 2026-10-09).
 *
 * After the how-to text and its check-ins, the tenant replies. The AI reads
 * that reply against the thread and says whether the tenant reports the fix
 * worked, with a confidence. A confident yes labels the card "Ready to close"
 * with that number; anything else leaves the card as it was, with the
 * reading in the "Tenant replied" chip's tooltip. The label never closes a
 * work order or moves a status: a coordinator does that by hand, which is
 * why the label exists.
 *
 * One reading per reply, stored as an AiInsight on the Conversation row (the
 * table's unique index makes a re-run update in place), so the sweep never
 * asks twice about the same text and a newer reply gets its own reading
 * that replaces the older one on the card.
 */
class TenantEasyFixReplyJudge
{
    /**
     * The activity log the readings are written to, for the audit trail.
     * Separate from TenantEasyFixService::VERDICT_LOG, whose newest row is
     * the board's "Auto" chip tooltip.
     */
    public const LOG_NAME = 'tenant_easy_fix_reply';

    /**
     * How many thread messages before the reply the judge reads.
     */
    private const CONTEXT_MESSAGES = 6;

    /**
     * Whether the judge runs: switched on and a provider configured.
     */
    public function enabled(): bool
    {
        return (bool) config('services.ai.easy_fix_reply_judge') && AiSettings::ready();
    }

    public function minimumConfidence(): int
    {
        return (int) config('services.ai.easy_fix_ready_min_confidence', 80);
    }

    /**
     * Open work orders on the easy-fix board whose newest tenant reply since
     * the easy fix began has not been read yet. HOA violations sit in the
     * same status but are not fixes; a vendor on the job means a coordinator
     * has moved on. Newest first.
     *
     * @return Collection<int, array{work_order: WorkOrder, reply: Conversation}>
     */
    public function candidates(?int $workOrderId = null, int $limit = 200): Collection
    {
        $workOrders = WorkOrder::query()
            ->tenantEasyFix()
            ->where('status', 'Open')
            ->whereDoesntHave('vendors')
            ->when($workOrderId !== null, fn ($query) => $query->whereKey($workOrderId))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->reject(fn (WorkOrder $workOrder) => $workOrder->isHoaViolation())
            ->values();

        if ($workOrders->isEmpty()) {
            return collect();
        }

        $ids = $workOrders->pluck('id')->all();

        $tokenStarts = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->whereIn('work_order_id', $ids)
            ->pluck('created_at', 'work_order_id');

        // is_read is the direction marker on work_order_conversations: false
        // means the tenant wrote it.
        $newestReplies = Conversation::query()
            ->withoutGlobalScopes()
            ->whereIn('work_order_id', $ids)
            ->where('conversation_type', 'tenant')
            ->where('is_read', false)
            ->orderBy('id')
            ->get()
            ->keyBy('work_order_id');

        $judgedReplyIds = AiInsight::query()
            ->ofType(AiInsight::TYPE_EASY_FIX_READY)
            ->where('subject_type', Conversation::class)
            ->whereIn('subject_id', $newestReplies->pluck('id')->all())
            ->pluck('subject_id')
            ->flip();

        return $workOrders
            ->map(function (WorkOrder $workOrder) use ($tokenStarts, $newestReplies, $judgedReplyIds): ?array {
                $reply = $newestReplies->get($workOrder->id);

                if ($reply === null || $judgedReplyIds->has($reply->id)) {
                    return null;
                }

                $since = $tokenStarts->get($workOrder->id)
                    ?? $workOrder->easy_fix_assessed_at
                    ?? $workOrder->created_at;

                if ($since !== null && $reply->created_at !== null && $reply->created_at->lt($since)) {
                    return null;
                }

                return ['work_order' => $workOrder, 'reply' => $reply];
            })
            ->filter()
            ->values();
    }

    /**
     * Read one reply and store the reading. Null when the AI could not be
     * asked (the sweep tries again next run); nothing is stored for a
     * failure so the card never shows a verdict the AI did not give.
     */
    public function judge(WorkOrder $workOrder, Conversation $reply): ?AiInsight
    {
        try {
            $response = (new TenantEasyFixReplyJudgeAgent)->prompt($this->prompt($workOrder, $reply), timeout: 30);
        } catch (\Throwable $exception) {
            Log::warning('Tenant easy-fix reply judge failed; the reply will be read on the next run.', [
                'work_order_id' => $workOrder->id,
                'conversation_id' => $reply->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $resolved = (bool) data_get($response, 'resolved', false);
        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $reason = Str::limit(trim((string) data_get($response, 'reason', '')), 200);
        $ready = $resolved && $confidence >= $this->minimumConfidence();

        $insight = AiInsight::query()->updateOrCreate(
            [
                'type' => AiInsight::TYPE_EASY_FIX_READY,
                'subject_type' => Conversation::class,
                'subject_id' => $reply->id,
            ],
            [
                'work_order_id' => $workOrder->id,
                'status' => AiInsight::STATUS_OPEN,
                'confidence' => $confidence,
                'data' => [
                    'ready' => $ready,
                    'resolved' => $resolved,
                    'reason' => $reason,
                    'easy_fix_key' => $workOrder->easy_fix_key,
                ],
                'generated_at' => now(),
            ],
        );

        $this->recordReading($workOrder, $reply, $insight);

        return $insight;
    }

    /**
     * The newest reading for a work order, as the Recommendation tab shows
     * it, or null when no reply has been read.
     *
     * @return array{ready: bool, resolved: bool, confidence: int, reason: string, judged_at: ?string}|null
     */
    public function latestFor(WorkOrder $workOrder): ?array
    {
        $insight = AiInsight::query()
            ->ofType(AiInsight::TYPE_EASY_FIX_READY)
            ->where('work_order_id', $workOrder->id)
            ->orderByDesc('id')
            ->first();

        return $insight === null ? null : self::summarize($insight);
    }

    /**
     * @return array{ready: bool, resolved: bool, confidence: int, reason: string, judged_at: ?string}
     */
    public static function summarize(AiInsight $insight): array
    {
        return [
            'ready' => (bool) data_get($insight->data, 'ready', false),
            'resolved' => (bool) data_get($insight->data, 'resolved', false),
            'confidence' => (int) $insight->confidence,
            'reason' => (string) data_get($insight->data, 'reason', ''),
            'judged_at' => $insight->generated_at?->toJSON(),
        ];
    }

    /**
     * One line a coordinator can read, the same text the audit row carries.
     */
    public static function describe(AiInsight $insight): string
    {
        $reading = self::summarize($insight);

        $verdict = match (true) {
            $reading['ready'] => 'reply says fixed',
            $reading['resolved'] => 'reply says fixed, unsure',
            default => 'reply says not fixed',
        };

        return "{$verdict} ({$reading['confidence']}%): {$reading['reason']}";
    }

    private function prompt(WorkOrder $workOrder, Conversation $reply): string
    {
        $item = TenantEasyFixCriteria::item($workOrder->easy_fix_key);

        $lines = [
            'WORK ORDER #'.($workOrder->work_order_no ?? $workOrder->id),
            'Handbook item: '.($item['label'] ?? $workOrder->easy_fix_key ?? 'n/a'),
            'Request: '.Str::limit(trim((string) $workOrder->description), 500),
            '',
            'TENANT THREAD (oldest first; "us" = the property manager, "them" = the tenant):',
        ];

        foreach ($this->threadBefore($reply) as $earlier) {
            $lines[] = sprintf('    %s: "%s"', $earlier->is_read ? 'us' : 'them', $this->excerpt($earlier->message));
        }

        $lines[] = sprintf('    them (NEWEST, judge this): "%s"', $this->excerpt($reply->message, 600));

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function threadBefore(Conversation $reply): Collection
    {
        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $reply->work_order_id)
            ->where('conversation_type', 'tenant')
            ->where('id', '<', $reply->id)
            ->orderByDesc('id')
            ->limit(self::CONTEXT_MESSAGES)
            ->get(['id', 'message', 'is_read'])
            ->reverse()
            ->values();
    }

    private function excerpt(?string $message, int $limit = 240): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', (string) $message) ?? ''), $limit);
    }

    private function recordReading(WorkOrder $workOrder, Conversation $reply, AiInsight $insight): void
    {
        try {
            activity(self::LOG_NAME)
                ->performedOn($workOrder)
                ->event(data_get($insight->data, 'ready') ? 'easy_fix_ready' : 'easy_fix_not_ready')
                ->withProperties([
                    'conversation_id' => $reply->id,
                    'confidence' => (int) $insight->confidence,
                    'resolved' => (bool) data_get($insight->data, 'resolved'),
                ])
                ->log(Str::limit(self::describe($insight), 250));
        } catch (\Throwable $exception) {
            Log::warning('Recording the tenant easy-fix reply reading failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
