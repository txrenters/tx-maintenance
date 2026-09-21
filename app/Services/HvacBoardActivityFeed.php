<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What actually moved on the HVAC board since a user last marked it seen.
 *
 * The badge answers "how many"; this answers "which ones, and what happened".
 * Each child table carries its own created_at, so a new message, note, photo,
 * task, invoice or vendor assignment can be named outright.
 *
 * What cannot be named: which status a work order moved BETWEEN, or which field
 * was edited. There is no service_status_changed_at column and activity_log is
 * not a change feed (a handful of rows, all from specific automations), so a
 * status move or a field edit shows as the honest fallback "updated" rather
 * than an invented description.
 *
 * Only ever called from the dropdown's own endpoint, never on board load or
 * poll: the count that took production down on 2026-09-11 was one that ran on
 * every request, and this is deliberately the opposite.
 */
class HvacBoardActivityFeed
{
    /** Never hand the dropdown an unbounded list. */
    private const MAX_ROWS = 50;

    /**
     * The child tables worth naming, as table => label. Each must carry
     * work_order_id and created_at.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        'work_order_conversations' => 'new message',
        'work_order_notes' => 'note added',
        'attachments' => 'photo or file added',
        'invoices' => 'invoice added',
        'work_order_vendors' => 'vendor assigned',
    ];

    /**
     * @return array<int, array{
     *     id:int, work_order_no:mixed, location:?string, category:?string,
     *     status:?string, change:string, at:?string
     * }>
     */
    public function for(User $user): array
    {
        $seenAt = $user->hvac_board_seen_at;

        if ($seenAt === null) {
            return [];
        }

        // The work orders whose own row moved. Scoped the same way the board is,
        // so the dropdown can never name a work order the board would hide.
        $moved = WorkOrder::query()
            ->scoped()
            ->select(['id', 'work_order_no', 'location', 'category', 'service_status_id', 'updated_at'])
            ->with('service_status:id,name')
            ->where('status', 'Open')
            ->hvac()
            ->where('updated_at', '>', $seenAt)
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ROWS)
            ->get();

        if ($moved->isEmpty()) {
            return [];
        }

        $changes = $this->namedChanges($moved->pluck('id')->all(), $seenAt);

        return $moved->map(fn (WorkOrder $workOrder): array => [
            'id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'location' => $workOrder->location,
            'category' => $workOrder->category,
            'status' => $workOrder->service_status?->name,
            // No child record explains it, so the row itself changed: a status
            // move, or an edited field. Say "updated" rather than guess.
            'change' => $changes[$workOrder->id] ?? 'updated',
            'at' => optional($workOrder->updated_at)->toJSON(),
        ])->all();
    }

    /**
     * The newest named change per work order, across the child tables.
     *
     * One small query per table, each restricted to the handful of work order
     * ids already in hand — no join back to work_orders, no JSON, and nothing
     * that touches the activity log.
     *
     * @param  array<int, int>  $workOrderIds
     * @return array<int, string>
     */
    private function namedChanges(array $workOrderIds, Carbon $seenAt): array
    {
        $newest = [];
        $newestAt = [];

        foreach (self::SOURCES as $table => $label) {
            $rows = DB::table($table)
                ->selectRaw('work_order_id, MAX(created_at) as last_at')
                ->whereIn('work_order_id', $workOrderIds)
                ->where('created_at', '>', $seenAt)
                ->groupBy('work_order_id')
                ->get();

            foreach ($rows as $row) {
                $id = (int) $row->work_order_id;

                // Several things may have happened; name the most recent.
                if (! isset($newestAt[$id]) || $row->last_at > $newestAt[$id]) {
                    $newestAt[$id] = $row->last_at;
                    $newest[$id] = $label;
                }
            }
        }

        return $newest;
    }
}
