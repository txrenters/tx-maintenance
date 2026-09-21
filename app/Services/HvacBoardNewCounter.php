<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;

/**
 * How many HVAC work orders have moved since a user last marked the board seen.
 *
 * Drives the number beside HVAC in the sidebar, so the coordinator sees that
 * something moved without having to open the board first.
 *
 * Deliberately one indexed pass over one table: status = 'Open' uses the
 * (status, service_status_id) index and leaves a few hundred rows for the
 * category match to narrow. The last badge count that shipped here joined a
 * JSON-extract derived table over the activity log on every staff request and
 * took production down on 2026-09-11, so this one holds to a single table, no
 * joins and no JSON.
 */
class HvacBoardNewCounter
{
    /**
     * Matches UnreadThreadCounter: long enough that a page load is not a query,
     * short enough that the number is never badly stale. Cleared outright when
     * the user marks the board seen.
     */
    private const CACHE_SECONDS = 60;

    public function cachedCountFor(User $user): int
    {
        return Cache::remember(
            $this->cacheKey($user->id),
            self::CACHE_SECONDS,
            fn (): int => $this->countFor($user),
        );
    }

    public function forgetFor(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    private function countFor(User $user): int
    {
        // Never marked seen: show nothing rather than every open work order,
        // the same reason the migration backfills existing users to now().
        if ($user->hvac_board_seen_at === null) {
            return 0;
        }

        return WorkOrder::query()
            ->where('status', 'Open')
            ->hvac()
            ->where('updated_at', '>', $user->hvac_board_seen_at)
            ->whereNotExists(fn ($q) => $this->dismissedSince($q, $user->id))
            ->count();
    }

    /**
     * Rows this user dismissed individually, and that have not moved since.
     *
     * A correlated EXISTS on a two-column unique index, not a join or a
     * subquery over the whole table: it is checked only for the handful of work
     * orders that already passed the updated_at filter.
     *
     * Comparing against dismissed_at (rather than just "a row exists") is what
     * makes a work order come back when it changes again.
     */
    private function dismissedSince($query, int $userId): void
    {
        $query->selectRaw('1')
            ->from('hvac_board_reads')
            ->whereColumn('hvac_board_reads.work_order_id', 'work_orders.id')
            ->where('hvac_board_reads.user_id', $userId)
            ->whereColumn('hvac_board_reads.dismissed_updated_at', '>=', 'work_orders.updated_at');
    }

    private function cacheKey(int $userId): string
    {
        return "hvac_board_new_count.{$userId}";
    }
}
