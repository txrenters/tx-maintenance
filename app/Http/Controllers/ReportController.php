<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function authorizeReports(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'woc']), 403);
    }

    /**
     * @return array{0:int,1:int,2:Carbon,3:Carbon}
     */
    private function monthRange(Request $request): array
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $month = (int) ($request->input('month') ?: now()->month);
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [$year, $month, $start, $end];
    }

    /**
     * Years to offer in the filter: from the current year back to the earliest
     * work-order created year actually present in the database.
     *
     * @return array<int, int>
     */
    private function years(): array
    {
        $earliest = WorkOrder::query()->whereNotNull('created_date')->min('created_date');
        $startYear = $earliest ? (int) Carbon::parse($earliest)->year : (int) now()->year;
        $endYear = (int) now()->year;

        return range($endYear, min($startYear, $endYear));
    }

    /**
     * Whole calendar days between two dates, ignoring time-of-day.
     */
    private function wholeDays($from, $to): int
    {
        return (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay());
    }

    /**
     * KPI: work orders not resolved within 7 days (created -> completed/today > 7 days).
     */
    public function unresolvedWithin7Days(Request $request)
    {
        $this->authorizeReports($request);
        [$year, $month, $start, $end] = $this->monthRange($request);
        $today = now();

        $workOrders = WorkOrder::query()->with('service_status')
            ->whereBetween('created_date', [$start, $end])
            ->orderBy('created_date')
            ->get();

        // A work order counts as resolved only when it is Closed.
        $closedDate = fn (WorkOrder $wo) => ($wo->status === 'Closed' && $wo->completed_date)
            ? Carbon::parse($wo->completed_date)
            : null;

        $days = fn (WorkOrder $wo) => $this->wholeDays($wo->created_date, $closedDate($wo) ?? $today);

        $map = fn (WorkOrder $wo) => [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'description' => $wo->description,
            'location' => $wo->location,
            'status' => $wo->service_status?->name ?? $wo->status,
            'created_date' => $wo->created_date,
            'resolution' => $closedDate($wo) ? $wo->completed_date : 'Open',
            'days' => $days($wo),
        ];

        // Not resolved within 7 days: still open (not closed) and past 7 days.
        $breached = $workOrders->filter(fn (WorkOrder $wo) => $wo->created_date
            && $wo->status !== 'Closed'
            && $this->wholeDays($wo->created_date, $today) > 7);
        // Resolved within 7 days: Closed within 7 days of creation.
        $compliant = $workOrders->filter(fn (WorkOrder $wo) => $closedDate($wo) && $days($wo) <= 7);
        // Resolved after 7 days: Closed, but it took more than 7 days.
        $closedLate = $workOrders->filter(fn (WorkOrder $wo) => $closedDate($wo) && $days($wo) > 7);

        return $this->respond('unresolved_7_days', [
            'title' => 'WOs Not Resolved Within 7 Days',
            'description' => 'Open work orders created this month that are still not closed after 7 days. The other tab lists those closed within 7 days.',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $workOrders->count(),
            'breached' => $breached->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'truncate'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_date', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'resolution', 'label' => 'Completed', 'type' => 'date'],
                ['key' => 'days', 'label' => 'Days', 'type' => 'right'],
            ],
            'lists' => [
                ['key' => 'breached', 'label' => 'Not resolved within 7 days', 'rows' => $breached->map($map)->values()],
                ['key' => 'compliant', 'label' => 'Resolved within 7 days', 'rows' => $compliant->map($map)->values()],
                ['key' => 'closed_late', 'label' => 'Resolved after 7 days', 'rows' => $closedLate->map($map)->values()],
            ],
        ]);
    }

    /**
     * KPI: work orders not scheduled within 3 business days of creation.
     */
    public function notScheduledWithin3Days(Request $request)
    {
        $this->authorizeReports($request);
        [$year, $month, $start, $end] = $this->monthRange($request);

        // Only open work orders are still relevant for scheduling; closed ones
        // were resolved so a missing schedule no longer matters.
        $workOrders = WorkOrder::query()->with(['service_status', 'service_schedules'])
            ->where('status', 'Open')
            ->whereBetween('created_date', [$start, $end])
            ->orderBy('created_date')
            ->get();

        $evaluate = function (WorkOrder $wo) {
            $created = $wo->created_date ? Carbon::parse($wo->created_date) : null;
            $firstSchedule = $wo->service_schedules->sortBy('created_at')->first();
            $scheduledAt = $firstSchedule?->created_at ? Carbon::parse($firstSchedule->created_at) : null;
            $bizDays = ($created && $scheduledAt) ? (int) $created->diffInWeekdays($scheduledAt) : null;
            $breached = ! $scheduledAt || ($bizDays !== null && $bizDays > 3);

            return [
                'breached' => $breached,
                'row' => [
                    'id' => $wo->id,
                    'work_order_no' => $wo->work_order_no,
                    'description' => $wo->description,
                    'status' => $wo->service_status?->name ?? $wo->status,
                    'created_date' => $wo->created_date,
                    'scheduled_on' => $scheduledAt ? $scheduledAt->toDateString() : 'Never scheduled',
                    'biz_days' => $scheduledAt ? $bizDays : '—',
                ],
            ];
        };

        $evaluated = $workOrders->map($evaluate);
        $breached = $evaluated->where('breached', true)->pluck('row')->values();
        $compliant = $evaluated->where('breached', false)->pluck('row')->values();

        return $this->respond('not_scheduled_3_days', [
            'title' => 'WOs Not Scheduled Within 3 Business Days',
            'description' => 'Open work orders created this month that were not given a service schedule within 3 business days (weekends excluded).',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $workOrders->count(),
            'breached' => $breached->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'truncate'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_date', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'scheduled_on', 'label' => 'First Scheduled'],
                ['key' => 'biz_days', 'label' => 'Biz days', 'type' => 'right'],
            ],
            'lists' => [
                ['key' => 'breached', 'label' => 'Not scheduled in 3 days', 'rows' => $breached],
                ['key' => 'compliant', 'label' => 'Scheduled in 3 days', 'rows' => $compliant],
            ],
        ]);
    }

    /**
     * KPI: work-order tasks not completed by their due date.
     */
    public function tasksCompletedOnTime(Request $request)
    {
        $this->authorizeReports($request);
        [$year, $month, $start, $end] = $this->monthRange($request);
        $today = now();

        $isLate = function (WorkOrderTask $task) use ($today) {
            if (! $task->due_date) {
                return false;
            }
            $due = Carbon::parse($task->due_date)->endOfDay();
            if ($task->status === 'completed') {
                return Carbon::parse($task->updated_at)->gt($due);
            }

            return $due->lt($today);
        };

        // Same basis as the other reports: open work orders created this month.
        // A work order breaches if any of its tasks were completed late or are
        // still pending past due.
        $workOrders = WorkOrder::query()->with(['service_status', 'tasks'])
            ->where('status', 'Open')
            ->whereBetween('created_date', [$start, $end])
            ->orderBy('created_date')
            ->get();

        $rows = $workOrders->map(function (WorkOrder $wo) use ($isLate) {
            $lateCount = $wo->tasks->filter($isLate)->count();

            return [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'location' => $wo->location,
                'status' => $wo->service_status?->name ?? $wo->status,
                'late' => $lateCount,
                'tasks' => $wo->tasks->count(),
            ];
        });

        [$breached, $compliant] = $rows->partition(fn ($row) => $row['late'] > 0);

        return $this->respond('tasks_on_time', [
            'title' => 'Tasks Not Completed On Time',
            'description' => 'Open work orders created this month with at least one task completed late or still pending past due.',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $rows->count(),
            'breached' => $breached->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'location', 'label' => 'Location', 'type' => 'truncate'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'late', 'label' => 'Late tasks', 'type' => 'right'],
                ['key' => 'tasks', 'label' => 'Tasks', 'type' => 'right'],
            ],
            'lists' => [
                ['key' => 'breached', 'label' => 'WOs with late tasks', 'rows' => $breached->values()],
                ['key' => 'compliant', 'label' => 'WOs all on time', 'rows' => $compliant->values()],
            ],
        ]);
    }

    /**
     * KPI: open work orders older than 30 days (per created month, target = 0).
     */
    public function openOver30Days(Request $request)
    {
        $this->authorizeReports($request);
        [$year, $month, $start, $end] = $this->monthRange($request);
        $today = now();
        $cutoff = $today->copy()->subDays(30);

        // Closed work orders are excluded — this is about open backlog.
        $workOrders = WorkOrder::query()->with('service_status')
            ->where('status', 'Open')
            ->whereBetween('created_date', [$start, $end])
            ->orderBy('created_date')
            ->get();

        $map = fn (WorkOrder $wo) => [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'description' => $wo->description,
            'location' => $wo->location,
            'status' => $wo->service_status?->name ?? $wo->status,
            'created_date' => $wo->created_date,
            'days' => $this->wholeDays($wo->created_date, $today),
        ];

        $isOld = fn (WorkOrder $wo) => $wo->created_date
            && Carbon::parse($wo->created_date)->lt($cutoff);

        $breached = $workOrders->filter($isOld);
        $compliant = $workOrders->reject($isOld);

        return $this->respond('open_over_30_days', [
            'title' => 'Open WOs Over 30 Days Old',
            'description' => 'Work orders created this month that are still open more than 30 days later. Target is zero.',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $workOrders->count(),
            'breached' => $breached->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'truncate'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_date', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'days', 'label' => 'Days open', 'type' => 'right'],
            ],
            'lists' => [
                ['key' => 'breached', 'label' => 'Open over 30 days', 'rows' => $breached->map($map)->values()],
                ['key' => 'compliant', 'label' => 'Open within 30 days', 'rows' => $compliant->map($map)->values()],
            ],
        ]);
    }

    /**
     * Build the shared KPI report response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(string $reportKey, array $payload)
    {
        $total = $payload['total'];
        $breached = $payload['breached'];

        return inertia('Reports/Kpi', array_merge($payload, [
            'reportKey' => $reportKey,
            'years' => $this->years(),
            'percentage' => $total > 0 ? round($breached / $total * 100, 1) : 0,
        ]));
    }
}
