<?php

namespace App\Services;

use App\Ai\Agents\CourtesyCloserAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the awaiting-reply features honest about courtesy closers.
 *
 * A tenant ending a conversation with "thank you!" leaves their thread's
 * newest message inbound, and every awaiting-reply feature (nav badge, Inbox,
 * board summary, unanswered report) would hold that thread open forever —
 * nothing can flip is_read without destroying the direction information it
 * encodes.
 *
 * Instead, the scheduled classifier asks the AI which of the newest inbound
 * messages are pure courtesy closers and remembers the verdicts here, and the
 * consumers subtract those message ids. Every failure mode fails open:
 * unclassified, AI down, gate off, cache emptied — the thread simply counts
 * as awaiting, exactly as before this feature existed.
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
     * How many unchecked messages one run sends to the AI. Sized so the
     * pre-existing backlog (~1000 awaiting threads at launch) drains within a
     * few scheduled runs while keeping each prompt comfortably small.
     */
    public const BATCH_LIMIT = 120;

    /** Longer than this is never a courtesy closer; judged locally, no AI. */
    private const OBVIOUS_LENGTH = 200;

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

        // A long message is never a courtesy closer — settled here so the AI
        // only ever sees short texts and truncation can never mislead it.
        foreach ($unchecked as $id => $text) {
            if (mb_strlen($text) > self::OBVIOUS_LENGTH) {
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
     * The newest inbound message of every thread, id => single-line text.
     *
     * Mirrors AwaitingReplyCounter's grouping exactly — same thread key, same
     * MAX(id) — because these verdicts are subtracted from that count. MMS
     * rows are excluded outright: a photo can be proof of completed work (HOA
     * fixes arrive this way) and must never be waved off as a pleasantry, and
     * blank texts carry nothing to judge.
     *
     * @return array<int, string>
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
            ->pluck('c.message', 'c.id')
            ->map(fn ($message) => trim(preg_replace('/\s+/', ' ', (string) $message)))
            ->filter(fn (string $message) => $message !== '')
            ->all();
    }

    /**
     * @param  array<int, string>  $batch
     */
    private function buildPrompt(array $batch): string
    {
        $lines = [
            'THE NEWEST UNANSWERED MESSAGE OF EACH CONVERSATION',
            'Name the refs that are pure courtesy closers. Leave out every doubt.',
            '',
        ];

        $ref = 0;

        foreach ($batch as $text) {
            $lines[] = sprintf('- ref %d | they wrote: "%s"', ++$ref, $text);
        }

        return implode("\n", $lines);
    }
}
