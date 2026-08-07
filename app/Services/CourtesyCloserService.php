<?php

namespace App\Services;

use App\Ai\Agents\CourtesyCloserAgent;
use App\Models\Conversation;
use App\Models\WorkOrder;
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

    /**
     * Whole messages that are pure gratitude or a conversation closer, judged
     * locally the moment they arrive — no AI call, no waiting for the next
     * scheduled run. Deliberately conservative: "ok" and "yes" are answers
     * (accepting an appointment, approving an estimate) and must never appear
     * here — the same reason "no problem" and "anytime" don't: they confirm
     * an appointment or answer "when works?". An entry earns its place only
     * when the entire message, stripped of punctuation, says nothing but
     * thanks/acknowledgment-of-thanks.
     *
     * @var array<int, string>
     */
    private const OBVIOUS_CLOSERS = [
        'thank you', 'thanks', 'thank u', 'ty', 'thx', 'many thanks',
        'thank you so much', 'thanks so much', 'thank you very much',
        'thanks a lot', 'thank you sir', 'thank you maam',
        'ok thank you', 'ok thanks', 'okay thank you', 'okay thanks',
        'got it thank you', 'got it thanks',
        'perfect thank you', 'perfect thanks',
        'great thank you', 'great thanks',
        'awesome thank you', 'awesome thanks',
        'alright thank you', 'alright thanks',
        'sounds good thank you', 'sounds good thanks',
        'my pleasure', 'youre welcome', 'you are welcome',
    ];

    /** Emoji that, standing alone as the whole message, close a conversation. */
    private const CLOSER_EMOJI = ['👍', '🙏', '❤️', '👌'];

    /**
     * Threads whose newest message is older than this are left unjudged (they
     * simply keep counting as awaiting) so the classifier never walks the
     * whole conversation history each run.
     */
    private const CANDIDATE_WINDOW_DAYS = 90;

    /**
     * Hard ceiling on how many ids the consumers inline into their
     * whereNotIn() — each id is a bound parameter, and an unbounded list
     * could outgrow the SQL packet on an awaiting-set spike. Overflow ids
     * just keep counting as awaiting.
     */
    private const MAX_FILTER_IDS = 2000;

    /**
     * Memoized courtesyIds() so one consumer instance (InboxController reads
     * it twice per request) hits the cache store once.
     *
     * @var array<int, int>|null
     */
    private ?array $memoizedCourtesyIds = null;

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

        return $this->memoizedCourtesyIds ??= array_slice(
            array_map('intval', array_keys(Cache::get(self::COURTESY_KEY, []))),
            0,
            self::MAX_FILTER_IDS
        );
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

        // A long message always needs a person, and a bare "thank you" never
        // does — both settled here so the AI only ever sees the genuinely
        // ambiguous middle and truncation can never mislead it. Tapbacks are
        // judged before the length guard: their quoted original pushes them
        // past OBVIOUS_LENGTH, which would otherwise pin them as
        // needs-a-person forever.
        foreach ($unchecked as $id => $row) {
            if (($tapback = TapbackDetector::detect($row->text)) !== null) {
                $checked[$id] = true;
                if (! $tapback['needs_reply']) {
                    $courtesy[$id] = true;
                }
                unset($unchecked[$id]);
                $judged++;
            } elseif (mb_strlen($row->text) > self::OBVIOUS_LENGTH) {
                $checked[$id] = true;
                unset($unchecked[$id]);
                $judged++;
            } elseif ($this->isObviousCloser($row->text)) {
                $courtesy[$id] = true;
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

        // A cache-store hiccup must not fail the scheduled run: verdicts just
        // go unrecorded and the next run re-judges the same candidates.
        try {
            Cache::forever(self::CHECKED_KEY, $checked);
            Cache::forever(self::COURTESY_KEY, array_intersect_key($courtesy, $checked));
            $this->memoizedCourtesyIds = null;

            // The badge caches its count for a minute; a fresh verdict should
            // show on the next page load, not after the TTL.
            app(AwaitingReplyCounter::class)->forget();
        } catch (\Throwable $e) {
            Log::warning('Courtesy closer verdicts could not be stored; the next run will re-judge.', [
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'judged' => $judged,
            'courtesy' => count($courtesy),
            'pending' => count($candidates) - count($checked),
        ];
    }

    /**
     * Judge one just-arrived inbound message locally, so a bare "thank you"
     * leaves the badge within a page load instead of waiting up to ten
     * minutes for the scheduled run. Only the conservative whole-message list
     * is consulted — anything ambiguous stays for the AI.
     *
     * Log-never-throw: storing a message must never fail over its verdict. A
     * lost race between two simultaneous arrivals just leaves a verdict
     * unrecorded, and the scheduled classifier covers it on the next run.
     */
    public function recordObviousCloser(Conversation $message): void
    {
        try {
            if (! $this->enabled() || $message->is_read || $message->is_mms) {
                return;
            }

            // A tapback reaction is settled on arrival: positive ones close
            // the thread, and Disliked/Questioned are pinned checked-without-
            // courtesy so the AI can never wave them off either.
            if (($tapback = TapbackDetector::detect($message->message)) !== null) {
                $this->storeVerdict($message->id, courtesy: ! $tapback['needs_reply']);

                return;
            }

            if (! $this->isObviousCloser($this->normalize($message->message))) {
                return;
            }

            $this->storeVerdict($message->id, courtesy: true);
        } catch (\Throwable $e) {
            Log::warning('Obvious courtesy closer could not be recorded; the scheduled run will judge it.', [
                'conversation_id' => $message->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record one just-arrived message's verdict. Checked always; courtesy
     * only when no reply is owed, matching how classify() stores the pair.
     */
    private function storeVerdict(int $conversationId, bool $courtesy): void
    {
        $checked = Cache::get(self::CHECKED_KEY, []);
        $checked[$conversationId] = true;
        Cache::forever(self::CHECKED_KEY, $checked);

        if ($courtesy) {
            $courtesyIds = Cache::get(self::COURTESY_KEY, []);
            $courtesyIds[$conversationId] = true;
            Cache::forever(self::COURTESY_KEY, $courtesyIds);
        }

        $this->memoizedCourtesyIds = null;
    }

    /**
     * Whether the whole message, stripped to its words, is on the
     * no-reply-needed list — or is nothing but closer emoji.
     */
    private function isObviousCloser(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        // Digits are load-bearing: "Thank you 8175551234" is a callback
        // number, "Thanks, $250" a quote, "thanks 4/15" a date — and the
        // normalizer below would erase all of them. Anything numeric goes to
        // the AI, which sees the digits and the thread.
        if (preg_match('/\d/', $text)) {
            return false;
        }

        $words = trim(preg_replace(
            '/\s+/',
            ' ',
            preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower($text))
        ));

        if ($words !== '' && in_array($words, self::OBVIOUS_CLOSERS, true)) {
            return true;
        }

        // No letters at all: an emoji-only message. It closes the thread only
        // when every character is a known closer emoji.
        if ($words === '') {
            $stripped = preg_replace('/\s+/u', '', $text);

            return $stripped !== ''
                && preg_replace('/(?:'.implode('|', array_map('preg_quote', self::CLOSER_EMOJI)).')+/u', '', $stripped) === '';
        }

        return false;
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
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->where('c.is_read', false)
            ->where('c.created_at', '>=', now()->subDays(self::CANDIDATE_WINDOW_DAYS))
            // Threads on closed work orders no longer count as awaiting
            // anywhere, so judging them would only spend AI on dead threads.
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
