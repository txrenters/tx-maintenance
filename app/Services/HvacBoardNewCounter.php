<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;

/**
 * How many work orders on an activity board (HVAC, Tenant Easy Fix; see
 * ActivityBoards) have moved since a user last marked that board seen.
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

    public function cachedCountFor(User $user, string $board = ActivityBoards::HVAC): int
    {
        return Cache::remember(
            $this->cacheKey($user->id, $board),
            self::CACHE_SECONDS,
            fn (): int => $this->countFor($user, $board),
        );
    }

    public function forgetFor(int $userId, string $board = ActivityBoards::HVAC): void
    {
        Cache::forget($this->cacheKey($userId, $board));
    }

    private function countFor(User $user, string $board): int
    {
        $definition = ActivityBoards::get($board);
        $seenAt = ActivityBoards::seenAt($user, $board);

        // No mark and no account date: show nothing rather than every open
        // work order, the same reason the migrations backfill users to now().
        if ($seenAt === null) {
            return 0;
        }

        return WorkOrder::query()
            ->where('status', 'Open')
            ->{$definition['scope']}()
            ->where('updated_at', '>', $seenAt)
            ->whereNotExists(fn ($q) => self::dismissedSince($q, $user->id, $definition['reads_table']))
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
    public static function dismissedSince($query, int $userId, string $readsTable): void
    {
        $query->selectRaw('1')
            ->from($readsTable)
            ->whereColumn("{$readsTable}.work_order_id", 'work_orders.id')
            ->where("{$readsTable}.user_id", $userId)
            ->whereColumn("{$readsTable}.dismissed_updated_at", '>=', 'work_orders.updated_at');
    }

    private function cacheKey(int $userId, string $board): string
    {
        return "{$board}_board_new_count.{$userId}";
    }
}
