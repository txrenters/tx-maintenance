<?php

namespace App\Services;

use App\Ai\Agents\CourtesyCloserAgent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Keeps the awaiting-reply features honest about messages that need no reply.
 *
 * A tenant ending a conversation with "thank you!" leaves their thread's
 * newest message inbound, and every awaiting-reply feature (nav badge, Inbox,
 * board summary, unanswered report) would hold that thread open forever —
 * nothing can flip is_read without destroying the direction information it
 * encodes.
 *
 * Instead, the scheduled classifier shows the AI each thread's recent
 * back-and-forth and asks whether the newest inbound message needs a reply or
 * action from us; the no-reply verdicts are remembered here and the consumers
 * subtract those message ids. Every failure mode fails open: unclassified, AI
 * down, gate off, cache emptied — the thread simply counts as awaiting,
 * exactly as before this feature existed.
 *
 * Verdicts are cached by message id. Conversation rows are never edited and
 * MAX(id) only moves forward, so a verdict can never attach to the wrong
 * text; ids that are no longer a thread's newest message are pruned each run.
 */
class CourtesyCloserService
{
    /** Cache key: map of message id => true for judged courtesy closers. */
    private const COURTESY_KEY = 'inbox.courtesy_closer_ids';

    /** Cache key: map of message id => true for everything already judged. */
    private const CHECKED_KEY = 'inbox.courtesy_checked_ids';

    /**
     * How many unchecked messages one run sends to the AI. Each ref carries
     * its thread context, so this is sized to keep the prompt comfortably
     * small; the pre-existing backlog (~1000 awaiting threads at launch)
     * drains over the first few hours of scheduled runs.
     */
    public const BATCH_LIMIT = 50;

    /** How many earlier messages of the thread the AI sees for context. */
    private const CONTEXT_MESSAGES = 4;

    /** Longer than this never needs-no-reply; judged locally, no AI. */
    private const OBVIOUS_LENGTH = 200;

    /** Context lines are trimmed to this many characters. */
    private const CONTEXT_EXCERPT = 160;

    public function __construct(
        private readonly WorkOrderRecommendationService $recommendations,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.inbox.courtesy_filter', true);
    }

    /**
     * Message ids whose thread should not count as awaiting a reply.
     *
     * @return array<int, int>
     */
    public function courtesyIds(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map('intval', array_keys(Cache::get(self::COURTESY_KEY, [])));
    }

    /**
     * Judge the not-yet-judged newest inbound messages and store the verdicts.
     *
     * @return array{judged: int, courtesy: int, pending: int}
     */
    public function classify(): array
    {
        if (! $this->enabled()) {
            return ['judged' => 0, 'courtesy' => 0, 'pending' => 0];
        }

        $candidates = $this->candidates();

        // Prune to the current candidates: an id that is no longer a thread's
        // newest message can never matter again, and the sets stay bounded by
        // the number of live awaiting threads.
        $checked = array_intersect_key(Cache::get(self::CHECKED_KEY, []), $candidates);
        $courtesy = array_intersect_key(Cache::get(self::COURTESY_KEY, []), $candidates);

        $unchecked = array_diff_key($candidates, $checked);
        $judged = 0;

        // A long message always needs a person — settled here so the AI only
        // ever sees short texts and truncation can never mislead it.
        foreach ($unchecked as $id => $row) {
            if (mb_strlen($row->text) > self::OBVIOUS_LENGTH) {
                $checked[$id] = true;
                unset($unchecked[$id]);
                $judged++;
            }
        }

        $batch = array_slice($unchecked, 0, self::BATCH_LIMIT, preserve_keys: true);

        if ($batch !== [] && $this->recommendations->aiStatus()['ready']) {
            $ids = array_keys($batch);

            try {
                $response = app(CourtesyCloserAgent::class)->prompt($this->buildPrompt($batch));

                collect(data_get($response, 'courtesy_refs', []))
                    ->map(fn ($ref) => (int) $ref)
                    ->filter(fn (int $ref) => $ref >= 1 && $ref <= count($ids))
                    ->each(function (int $ref) use (&$courtesy, $ids) {
                        $courtesy[$ids[$ref - 1]] = true;
                    });

                foreach ($ids as $id) {
                    $checked[$id] = true;
                }

                $judged += count($ids);
            } catch (\Throwable $e) {
                Log::warning('Courtesy closer classification failed; leaving threads counted.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Cache::forever(self::CHECKED_KEY, $checked);
        Cache::forever(self::COURTESY_KEY, array_intersect_key($courtesy, $checked));

        // The badge caches its count for a minute; a fresh verdict should show
        // on the next page load, not after the TTL.
        app(AwaitingReplyCounter::class)->forget();

        return [
            'judged' => $judged,
            'courtesy' => count($courtesy),
            'pending' => count($candidates) - count($checked),
        ];
    }

    /**
     * The newest inbound message of every thread, id => row carrying the
     * normalized text and the thread key needed to fetch its context.
     *
     * Mirrors AwaitingReplyCounter's grouping exactly — same thread key, same
     * MAX(id) — because these verdicts are subtracted from that count. MMS
     * rows are excluded outright: a photo can be proof of completed work (HOA
     * fixes arrive this way) and must never be waved off as a pleasantry, and
     * blank texts carry nothing to judge.
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
            ->where('c.is_read', false)
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
     * Each ref is one thread: its recent back-and-forth for context, ending
     * with the newest message — the one actually being judged.
     *
     * @param  array<int, object>  $batch
     */
    private function buildPrompt(array $batch): string
    {
        $lines = [
            'CONVERSATIONS WHOSE NEWEST MESSAGE NOBODY HAS ANSWERED',
            'Name the refs whose newest message needs no reply and no action from us. Leave out every doubt.',
        ];

        $ref = 0;

        foreach ($batch as $row) {
            $lines[] = '';
            $lines[] = sprintf('- ref %d | %s conversation', ++$ref, filled($row->conversation_type) ? $row->conversation_type : 'unknown');

            foreach ($this->threadContext($row) as $earlier) {
                $lines[] = sprintf('    %s: "%s"', $earlier->is_read ? 'us' : 'them', $this->contextExcerpt($earlier));
            }

            $lines[] = sprintf('    them (NEWEST, judge this): "%s"', $row->text);
        }

        return implode("\n", $lines);
    }

    /**
     * The messages of the thread just before the judged one, oldest first.
     * Same thread identity the candidates are grouped by: work order, party,
     * vendor and owner, with blank and null treated alike.
     *
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
}
