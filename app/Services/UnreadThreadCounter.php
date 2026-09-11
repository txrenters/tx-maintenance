<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How many conversations hold a message this staff user has not looked at yet,
 * for the red badge on the Messages nav.
 *
 * Messenger-style on purpose: the badge counts what is new TO YOU, not the
 * whole reply backlog, so it drains as threads are opened — reading is enough,
 * replying is not required. A backlog number that never moves teaches people
 * to ignore the badge; the workload metric lives on the Inbox's own
 * "Awaiting reply" filter instead (see AwaitingReplyCounter).
 *
 * A thread counts when its newest message came in from the outside (is_read is
 * the direction column — nothing ever flips it) AND this user's read marker
 * (inbox_thread_reads, moved when they open the thread) has not caught up to
 * that message. Judged courtesy closers and closed work orders never count.
 * This matches the Inbox's "Unread" filter exactly, so clicking through from
 * the badge always finds the same number.
 */
class UnreadThreadCounter
{
    /**
     * Short enough that the badge tracks a reading session, long enough that
     * the grouped query does not run on every page view. Opening a thread
     * busts this user's entry immediately — see InboxController.
     */
    private const CACHE_SECONDS = 60;

    public function __construct(private readonly CourtesyCloserService $courtesyClosers) {}

    public function cachedCountFor(int $userId): int
    {
        return Cache::remember(
            $this->cacheKey($userId),
            self::CACHE_SECONDS,
            fn () => $this->countFor($userId)
        );
    }

    /**
     * Drop this user's cached value so the badge reflects a just-read thread
     * instead of waiting out the TTL. Other users keep their own entries.
     */
    public function forgetFor(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    public function countFor(int $userId): int
    {
        // MAX(id) rather than MAX(created_at): ids are monotonic, so this picks
        // one row per thread with no tie-breaking. Same thread key the Inbox
        // and InboxThreadRead use.
        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        $courtesyIds = $this->courtesyClosers->courtesyIds();

        return DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->leftJoin('inbox_thread_reads as r', function ($join) use ($userId) {
                $join->on('r.work_order_id', '=', 'c.work_order_id')
                    ->where('r.user_id', $userId)
                    ->whereRaw("r.conversation_type = COALESCE(NULLIF(c.conversation_type, ''), 'unknown')")
                    ->whereRaw('r.vendor_id = COALESCE(c.vendor_id, 0)')
                    ->whereRaw('r.owner_id = COALESCE(c.owner_id, 0)');
            })
            ->where('c.is_read', false)
            // A closed work order has nothing new worth a badge. NULL-safe: a
            // status-less row keeps counting rather than going silent.
            ->where(fn ($query) => $query
                ->whereNotIn('wo.status', WorkOrder::CLOSED_STATUSES)
                ->orWhereNull('wo.status')
            )
            // Unseen: no marker for this thread yet, or the newest message
            // arrived after the marker was last moved.
            ->where(fn ($query) => $query
                ->whereNull('r.id')
                ->orWhereColumn('c.id', '>', 'r.last_read_conversation_id')
            )
            ->when($courtesyIds !== [], fn ($query) => $query->whereNotIn('c.id', $courtesyIds))
            ->count();
    }

    private function cacheKey(int $userId): string
    {
        return 'inbox.unread_thread_count.'.$userId;
    }
}
