<?php

namespace App\Services;

use App\Ai\Agents\InboundMessageTriageAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tags each thread's newest unanswered inbound message with an intent
 * (reschedule request, complaint, access issue, job done, approval,
 * question, confirmation) and, when the message proposes a concrete
 * appointment day/time, records a schedule SUGGESTION for staff to accept or
 * dismiss.
 *
 * Read-only: this service never sends anything and never edits a work
 * order — it writes ai_insights rows the Inbox and the Service Schedule tab
 * render. Every verdict (including 'none') is stored per message so a
 * message is judged at most once; failures are logged and skipped, and the
 * next scheduled run simply tries again.
 *
 * Candidate selection mirrors CourtesyCloserService::candidates() — the
 * same thread grouping AwaitingReplyCounter uses — so triage always talks
 * about the same "latest inbound message" every other inbox surface shows.
 * MMS-only messages are excluded like the courtesy closer excludes them:
 * there is no text to classify.
 */
class MessageTriageService
{
    /**
     * Threads classified per AI call.
     */
    private const BATCH_LIMIT = 40;

    /**
     * Earlier messages of the thread shown as context per ref.
     */
    private const CONTEXT_MESSAGES = 4;

    private const CONTEXT_EXCERPT = 160;

    /**
     * Only messages this recent are triaged, and intent rows older than this
     * are pruned — matches the courtesy closer's candidate window.
     */
    private const WINDOW_DAYS = 90;

    /**
     * Appointment proposals further out than this are treated as extraction
     * errors and ignored.
     */
    private const MAX_SUGGESTION_DAYS_AHEAD = 365;

    public const TIMEZONE = 'America/Chicago';

    public function enabled(): bool
    {
        return (bool) config('services.inbox.intent_triage', true);
    }

    /**
     * Classify unjudged latest-inbound messages and store their insights.
     *
     * @return array{judged: int, suggested: int, pending: int}
     */
    public function classify(): array
    {
        if (! $this->enabled() || ! AiSettings::ready()) {
            return ['judged' => 0, 'suggested' => 0, 'pending' => 0];
        }

        $candidates = $this->candidates();

        $alreadyJudged = AiInsight::query()
            ->ofType(AiInsight::TYPE_MESSAGE_INTENT)
            ->where('subject_type', Conversation::class)
            ->whereIn('subject_id', array_keys($candidates))
            ->pluck('subject_id')
            ->all();

        $unjudged = array_diff_key($candidates, array_flip($alreadyJudged));

        // The courtesy closer already decided these need nothing from us —
        // store 'none' locally instead of spending tokens re-judging them.
        $judged = 0;
        $suggested = 0;

        foreach (app(CourtesyCloserService::class)->courtesyIds() as $courtesyId) {
            if (isset($unjudged[$courtesyId])) {
                $this->storeIntent($unjudged[$courtesyId], 'none', 'Needs no reply.');
                unset($unjudged[$courtesyId]);
                $judged++;
            }
        }

        $batch = array_slice($unjudged, 0, self::BATCH_LIMIT, preserve_keys: true);

        if ($batch !== []) {
            try {
                $response = (new InboundMessageTriageAgent)->prompt($this->buildPrompt($batch));

                $ids = array_keys($batch);

                foreach (data_get($response, 'items', []) as $item) {
                    $ref = (int) data_get($item, 'ref');

                    if ($ref < 1 || $ref > count($ids)) {
                        continue;
                    }

                    $row = $batch[$ids[$ref - 1]];
                    $intent = $this->normalizeIntent((string) data_get($item, 'intent'));
                    $summary = Str::limit(trim((string) data_get($item, 'summary')), 200);

                    $this->storeIntent($row, $intent, $summary);
                    $judged++;

                    if ($this->storeScheduleSuggestion($row, $item, $summary)) {
                        $suggested++;
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Inbound message triage failed; will retry next run.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->prune();

        return [
            'judged' => $judged,
            'suggested' => $suggested,
            'pending' => max(0, count($unjudged) - count($batch)),
        ];
    }

    /**
     * The stored intents for a set of latest-message ids, keyed by message
     * id — what the Inbox joins onto its thread rows. 'none' rows are
     * omitted; they exist only to prevent re-classification.
     *
     * @param  list<int>  $messageIds
     * @return array<int, array{intent: string, summary: ?string}>
     */
    public function intentsForMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        try {
            return AiInsight::query()
                ->ofType(AiInsight::TYPE_MESSAGE_INTENT)
                ->where('subject_type', Conversation::class)
                ->whereIn('subject_id', $messageIds)
                ->get(['subject_id', 'data'])
                ->filter(fn (AiInsight $insight) => data_get($insight->data, 'intent') !== 'none')
                ->mapWithKeys(fn (AiInsight $insight) => [
                    (int) $insight->subject_id => [
                        'intent' => (string) data_get($insight->data, 'intent'),
                        'summary' => data_get($insight->data, 'summary'),
                    ],
                ])
                ->all();
        } catch (\Throwable $exception) {
            // Fail open: an unreadable insights table (migration not run yet)
            // must never break the Inbox.
            Log::warning('Message intents unreadable; rendering the inbox without chips.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function storeIntent(object $row, string $intent, string $summary): void
    {
        AiInsight::query()->updateOrCreate(
            [
                'type' => AiInsight::TYPE_MESSAGE_INTENT,
                'subject_type' => Conversation::class,
                'subject_id' => $row->id,
            ],
            [
                'work_order_id' => $row->work_order_id,
                'status' => AiInsight::STATUS_OPEN,
                'data' => ['intent' => $intent, 'summary' => $summary],
                'generated_at' => now(),
            ],
        );
    }

    /**
     * Record an extracted appointment proposal as a suggestion. firstOrCreate
     * on purpose: a suggestion staff already dismissed must never resurrect.
     *
     * @param  array<string, mixed>  $item
     */
    private function storeScheduleSuggestion(object $row, mixed $item, string $summary): bool
    {
        $start = $this->parseSuggestedDate(data_get($item, 'schedule_start'));

        if ($start === null) {
            return false;
        }

        $end = $this->parseSuggestedDate(data_get($item, 'schedule_end'));

        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            $end = null;
        }

        AiInsight::query()->firstOrCreate(
            [
                'type' => AiInsight::TYPE_SCHEDULE_SUGGESTION,
                'subject_type' => Conversation::class,
                'subject_id' => $row->id,
            ],
            [
                'work_order_id' => $row->work_order_id,
                'status' => AiInsight::STATUS_OPEN,
                'data' => [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end?->format('Y-m-d H:i:s'),
                    'summary' => $summary,
                    'party' => filled($row->conversation_type) ? $row->conversation_type : 'unknown',
                    'quote' => Str::limit($row->text, self::CONTEXT_EXCERPT),
                ],
                'generated_at' => now(),
            ],
        );

        return true;
    }

    /**
     * A proposal only counts when it parses and lies between today and a
     * year out — anything else is an extraction mistake, not a suggestion.
     */
    private function parseSuggestedDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse(trim($value), self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        if ($date->lessThan($today) || $date->greaterThan($today->copy()->addDays(self::MAX_SUGGESTION_DAYS_AHEAD))) {
            return null;
        }

        return $date;
    }

    private function normalizeIntent(string $intent): string
    {
        $intent = strtolower(trim($intent));

        return in_array($intent, AiInsight::INTENTS, true) ? $intent : 'none';
    }

    /**
     * Same selection as CourtesyCloserService::candidates(): the newest
     * inbound message of every open thread inside the window.
     *
     * @return array<int, object>
     */
    private function candidates(): array
    {
        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        return DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->where('c.is_read', false)
            ->where('c.created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($query) => $query
                ->whereNotIn('wo.status', WorkOrder::CLOSED_STATUSES)
                ->orWhereNull('wo.status')
            )
            ->where(fn ($query) => $query->where('c.is_mms', false)->orWhereNull('c.is_mms'))
            ->orderBy('c.id')
            ->get(['c.id', 'c.work_order_id', 'c.conversation_type', 'c.vendor_id', 'c.owner_id', 'c.message'])
            ->keyBy('id')
            ->map(function (object $row) {
                $row->text = $this->normalize($row->message);

                return $row;
            })
            ->filter(fn (object $row) => $row->text !== '')
            ->all();
    }

    /**
     * @param  array<int, object>  $batch
     */
    private function buildPrompt(array $batch): string
    {
        $now = Carbon::now(self::TIMEZONE);

        $lines = [
            'CONVERSATIONS WHOSE NEWEST MESSAGE NOBODY HAS ANSWERED',
            sprintf('CURRENT DATE: %s (%s, timezone %s)', $now->format('Y-m-d H:i'), $now->format('l'), self::TIMEZONE),
            'Classify the NEWEST message of each ref and extract any concrete appointment proposal.',
        ];

        $ref = 0;

        foreach ($batch as $row) {
            $lines[] = '';
            $lines[] = sprintf('- ref %d | %s conversation', ++$ref, filled($row->conversation_type) ? $row->conversation_type : 'unknown');

            foreach ($this->threadContext($row) as $earlier) {
                $lines[] = sprintf('    %s: "%s"', $earlier->is_read ? 'us' : 'them', $this->contextExcerpt($earlier));
            }

            $lines[] = sprintf('    them (NEWEST, classify this): "%s"', $row->text);
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, object>
     */
    private function threadContext(object $row): Collection
    {
        return DB::table('work_order_conversations')
            ->where('work_order_id', $row->work_order_id)
            ->when(
                filled($row->conversation_type),
                fn ($query) => $query->where('conversation_type', $row->conversation_type),
                fn ($query) => $query->where(fn ($q) => $q->whereNull('conversation_type')->orWhere('conversation_type', ''))
            )
            ->when(
                $row->vendor_id !== null,
                fn ($query) => $query->where('vendor_id', $row->vendor_id),
                fn ($query) => $query->whereNull('vendor_id')
            )
            ->when(
                $row->owner_id !== null,
                fn ($query) => $query->where('owner_id', $row->owner_id),
                fn ($query) => $query->whereNull('owner_id')
            )
            ->where('id', '<', $row->id)
            ->orderByDesc('id')
            ->limit(self::CONTEXT_MESSAGES)
            ->get(['message', 'is_read', 'is_mms'])
            ->reverse()
            ->values();
    }

    private function contextExcerpt(object $message): string
    {
        $text = Str::limit($this->normalize($message->message), self::CONTEXT_EXCERPT);

        if ($message->is_mms) {
            return trim('[photo attachment] '.$text);
        }

        return $text;
    }

    private function normalize(?string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $message));
    }

    /**
     * Intent rows for messages that fell out of the candidate window carry no
     * value — the Inbox no longer shows those messages as latest. Suggestions
     * and photo reviews are low-volume and keep their audit value, so only
     * intents are pruned.
     */
    private function prune(): void
    {
        AiInsight::query()
            ->ofType(AiInsight::TYPE_MESSAGE_INTENT)
            ->where('created_at', '<', now()->subDays(self::WINDOW_DAYS))
            ->delete();
    }
}
