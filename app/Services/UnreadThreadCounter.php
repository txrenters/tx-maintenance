<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * A thread counts when its newest INCOMING message (is_read is the direction
 * column — nothing ever flips it) is one this user's read marker
 * (inbox_thread_reads, moved when they open the thread) has not caught up to,
 * AND no coordinator has answered it since. An automated text — an intake
 * notice, a reminder, a follow-up — is not an answer and never clears the
 * badge; the automated-message ledger tells the two apart (see
 * ThreadNewestMessages). Judged courtesy closers and closed work orders never
 * count. This matches the Inbox's "Unread" filter exactly, so clicking through
 * from the badge always finds the same number.
 */
class UnreadThreadCounter
{
    /**
     * Short enough that the badge tracks a reading session, long enough that
     * the grouped query does not run on every page view. Opening a thread
     * busts this user's entry immediately — see InboxController.
     */
    private const CACHE_SECONDS = 60;

    public function __construct(
        private readonly CourtesyCloserService $courtesyClosers,
        private readonly ThreadNewestMessages $newest,
    ) {}

    /**
     * The badge is computed on every page a coordinator opens, so a failure
     * here must never take the page down with it: it logs and shows no badge
     * until the next request.
     */
    public function cachedCountFor(int $userId): int
    {
        try {
            return Cache::remember(
                $this->cacheKey($userId),
                self::CACHE_SECONDS,
                fn () => $this->countFor($userId)
            );
        } catch (\Throwable $exception) {
            Log::warning('Unread thread badge could not be counted.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
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
        $courtesyIds = $this->courtesyClosers->courtesyIds();

        return DB::table('work_order_conversations as c')
            // Same thread key the Inbox and InboxThreadRead use; the row is the
            // thread's newest incoming message.
            ->joinSub($this->newest->perThread(), 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_inbound_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->leftJoin('inbox_thread_reads as r', function ($join) use ($userId) {
                $join->on('r.work_order_id', '=', 'c.work_order_id')
                    ->where('r.user_id', $userId)
                    ->whereRaw("r.conversation_type = COALESCE(NULLIF(c.conversation_type, ''), 'unknown')")
                    ->whereRaw('r.vendor_id = COALESCE(c.vendor_id, 0)')
                    ->whereRaw('r.owner_id = COALESCE(c.owner_id, 0)');
            })
            // A closed work order has nothing new worth a badge. NULL-safe: a
            // status-less row keeps counting rather than going silent.
            ->where(fn ($query) => $query
                ->whereNotIn('wo.status', WorkOrder::CLOSED_STATUSES)
                ->orWhereNull('wo.status')
            )
            // Unseen: no marker for this thread yet, or the message arrived
            // after the marker was last moved.
            ->where(fn ($query) => $query
                ->whereNull('r.id')
                ->orWhereColumn('c.id', '>', 'r.last_read_conversation_id')
            )
            // Unanswered by a person: an automated text sent since does not
            // count, a coordinator's reply does.
            ->where(fn ($query) => $query
                ->whereNull('latest.last_human_id')
                ->orWhereColumn('latest.last_human_id', '<', 'c.id')
            )
            ->when($courtesyIds !== [], fn ($query) => $query->whereNotIn('c.id', $courtesyIds))
            ->count();
    }

    private function cacheKey(int $userId): string
    {
        return 'inbox.unread_thread_count.'.$userId;
    }
}
