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
     * Which modal tab each named change belongs to, so clicking a row opens the
     * thing that actually changed instead of the Details tab. A change with no
     * entry here (the "updated" fallback) opens the modal as it always did.
     *
     * @var array<string, string>
     */
    private const CHANGE_TABS = [
        'new message' => 'conversation',
        'note added' => 'notes',
        'photo or file added' => 'attachments',
        'invoice added' => 'invoices',
        'vendor assigned' => 'vendor_edit',
    ];

    /**
     * @return array<int, array{
     *     id:int, work_order_no:mixed, location:?string, category:?string,
     *     status:?string, change:string, at:?string
     * }>
     */
    public function for(User $user, string $board = ActivityBoards::HVAC): array
    {
        $definition = ActivityBoards::get($board);
        $seenAt = ActivityBoards::seenAt($user, $board);

        if ($seenAt === null) {
            return [];
        }

        // The work orders whose own row moved. Scoped the same way the board is,
        // so the dropdown can never name a work order the board would hide.
        $moved = WorkOrder::query()
            ->scoped()
            ->select(['id', 'work_order_no', 'location', 'category', 'service_status_id', 'updated_at', 'last_change_summary'])
            ->with('service_status:id,name')
            ->where('status', 'Open')
            ->{$definition['scope']}()
            ->where('updated_at', '>', $seenAt)
            // Rows this user dismissed individually drop out, until the work
            // order moves again. Same predicate the badge count uses, so the
            // list and the number can never disagree.
            ->whereNotExists(fn ($q) => HvacBoardNewCounter::dismissedSince($q, $user->id, $definition['reads_table']))
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ROWS)
            ->get();

        if ($moved->isEmpty()) {
            return [];
        }

        $changes = $this->namedChanges($moved->pluck('id')->all(), $seenAt);

        return $moved->map(function (WorkOrder $workOrder) use ($changes): array {
            // A child record explains it best ("new message"); failing that the
            // row recorded its own change as it was saved ("moved to
            // Scheduled"). Only a row changed before that recording existed, or
            // in a way not worth naming, falls back to the generic word.
            $change = $changes[$workOrder->id]
                ?? $workOrder->last_change_summary
                ?? 'updated';

            return [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'location' => $workOrder->location,
                'category' => $workOrder->category,
                'status' => $workOrder->service_status?->name,
                'change' => $change,
                'tab' => self::CHANGE_TABS[$change] ?? null,
                'at' => optional($workOrder->updated_at)->toJSON(),
            ];
        })->all();
    }

    /**
     * Which of a single work order's tabs hold something added since this user
     * last marked the board seen, as tab name => count.
     *
     * Used for the dots on the work order modal's own tabs, so once the board
     * has said "this one moved" the modal can say where. Attachments is
     * deliberately absent: that tab already carries its own unseen count, which
     * clears on its own terms, and two competing badges would contradict.
     *
     * @return array<string, int>
     */
    public function tabCountsFor(User $user, WorkOrder $workOrder, string $board = ActivityBoards::HVAC): array
    {
        $seenAt = ActivityBoards::seenAt($user, $board);

        if ($seenAt === null || ! ActivityBoards::sees($user, $board)) {
            return [];
        }

        $tabs = [
            'conversation' => 'work_order_conversations',
            'notes' => 'work_order_notes',
            'invoices' => 'invoices',
        ];

        $counts = [];

        foreach ($tabs as $tab => $table) {
            $count = DB::table($table)
                ->where('work_order_id', $workOrder->id)
                ->where('created_at', '>', $seenAt)
                ->count();

            if ($count > 0) {
                $counts[$tab] = $count;
            }
        }

        return $counts;
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
