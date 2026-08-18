<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How many conversations are sitting on a reply from us — the workload metric
 * behind the Inbox's awaiting-reply views. (The red nav badge counts unseen
 * threads per user instead; see UnreadThreadCounter.)
 *
 * A thread is one work order plus one party, matching the Inbox and
 * BoardSummaryService. A thread counts when its newest message came in from the
 * outside: work_order_conversations has no direction column, but nothing ever
 * flips is_read from 0 to 1 — outbound writers set it true on insert and inbound
 * writers leave it false — so is_read is the direction.
 *
 * Threads whose newest message is a judged courtesy closer ("thank you", "ok
 * great") are subtracted — see CourtesyCloserService, which fails open, so an
 * unjudged thread always counts.
 */
class AwaitingReplyCounter
{
    /**
     * Short enough that a coordinator sees the badge drop soon after replying,
     * long enough that the grouped query does not run on every page view.
     */
    private const CACHE_SECONDS = 60;

    private const CACHE_KEY = 'inbox.awaiting_reply_count';

    /** Counting past this is pointless; the badge renders it as "99+". */
    public const DISPLAY_CAP = 99;

    public function __construct(private readonly CourtesyCloserService $courtesyClosers) {}

    public function cachedCount(): int
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_SECONDS,
            fn () => $this->count()
        );
    }

    /**
     * Drop the cached value so the badge reflects a just-sent reply instead of
     * waiting out the TTL.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function count(): int
    {
        // MAX(id) rather than MAX(created_at): ids are monotonic, so this picks
        // one row per thread with no tie-breaking.
        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        $courtesyIds = $this->courtesyClosers->courtesyIds();

        return DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->where('c.is_read', false)
            // A closed work order waits on nobody. NULL-safe: a status-less
            // row keeps counting rather than going silent.
            ->where(fn ($query) => $query
                ->whereNotIn('wo.status', WorkOrder::CLOSED_STATUSES)
                ->orWhereNull('wo.status')
            )
            ->when($courtesyIds !== [], fn ($query) => $query->whereNotIn('c.id', $courtesyIds))
            ->count();
    }
}
