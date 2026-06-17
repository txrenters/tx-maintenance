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
     * @return array<int, int>
     */
    private function years(): array
    {
        return range((int) now()->year, 2024);
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

        $rows = $workOrders->filter(function (WorkOrder $wo) use ($today) {
            if (! $wo->created_date) {
                return false;
            }
            $resolved = $wo->completed_date ? Carbon::parse($wo->completed_date) : $today;

            return Carbon::parse($wo->created_date)->diffInDays($resolved) > 7;
        })->map(fn (WorkOrder $wo) => [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'description' => $wo->description,
            'location' => $wo->location,
            'status' => $wo->service_status?->name ?? $wo->status,
            'created_date' => $wo->created_date,
            'resolution' => $wo->completed_date ?: 'Open',
            'days' => (int) round(Carbon::parse($wo->created_date)->diffInDays($wo->completed_date ? Carbon::parse($wo->completed_date) : $today)),
        ])->values();

        return $this->respond('unresolved_7_days', [
            'title' => 'WOs Not Resolved Within 7 Days',
            'description' => 'Work orders created this month that took more than 7 days to complete, or are still open after 7 days.',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $workOrders->count(),
            'breached' => $rows->count(),
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
            'rows' => $rows,
        ]);
    }

    /**
     * KPI: work orders not scheduled within 3 business days of creation.
     */
    public function notScheduledWithin3Days(Request $request)
    {
        $this->authorizeReports($request);
        [$year, $month, $start, $end] = $this->monthRange($request);

        $workOrders = WorkOrder::query()->with(['service_status', 'service_schedules'])
            ->whereBetween('created_date', [$start, $end])
            ->orderBy('created_date')
            ->get();

        $rows = $workOrders->map(function (WorkOrder $wo) {
            $created = $wo->created_date ? Carbon::parse($wo->created_date) : null;
            $firstSchedule = $wo->service_schedules
                ->sortBy('created_at')
                ->first();
            $scheduledAt = $firstSchedule?->created_at ? Carbon::parse($firstSchedule->created_at) : null;

            $bizDays = ($created && $scheduledAt) ? $created->diffInWeekdays($scheduledAt) : null;
            $breached = ! $scheduledAt || ($bizDays !== null && $bizDays > 3);

            return [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'description' => $wo->description,
                'status' => $wo->service_status?->name ?? $wo->status,
                'created_date' => $wo->created_date,
                'scheduled_on' => $scheduledAt ? $scheduledAt->toDateString() : 'Never scheduled',
                'biz_days' => $scheduledAt ? (int) $bizDays : '—',
                '_breached' => $breached,
            ];
        })->filter(fn ($row) => $row['_breached'])->map(function ($row) {
            unset($row['_breached']);

            return $row;
        })->values();

        return $this->respond('not_scheduled_3_days', [
            'title' => 'WOs Not Scheduled Within 3 Business Days',
            'description' => 'Work orders created this month that were not given a service schedule within 3 business days (weekends excluded).',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $workOrders->count(),
            'breached' => $rows->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'truncate'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_date', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'scheduled_on', 'label' => 'First Scheduled'],
                ['key' => 'biz_days', 'label' => 'Biz days', 'type' => 'right'],
            ],
            'rows' => $rows,
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

        $tasks = WorkOrderTask::query()->with('work_order')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')
            ->get();

        $rows = $tasks->filter(function (WorkOrderTask $task) use ($today) {
            $due = Carbon::parse($task->due_date)->endOfDay();
            if ($task->status === 'completed') {
                return Carbon::parse($task->updated_at)->gt($due); // completed late
            }

            return $due->lt($today); // still not done and overdue
        })->map(fn (WorkOrderTask $task) => [
            'id' => $task->work_order_id,
            'work_order_no' => $task->work_order?->work_order_no,
            'description' => $task->description,
            'due_date' => $task->due_date,
            'status' => $task->status,
            'completed_on' => $task->status === 'completed' ? $task->updated_at : 'Not done',
        ])->values();

        return $this->respond('tasks_on_time', [
            'title' => 'Tasks Not Completed On Time',
            'description' => 'Work-order tasks due this month that were completed after their due date, or are still pending past due.',
            'hasMonthFilter' => true,
            'filters' => ['year' => $year, 'month' => $month],
            'total' => $tasks->count(),
            'breached' => $rows->count(),
            'countLabel' => 'No. of Tasks',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Task', 'type' => 'truncate'],
                ['key' => 'due_date', 'label' => 'Due', 'type' => 'date'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'completed_on', 'label' => 'Completed', 'type' => 'date'],
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * KPI: open work orders older than 30 days (snapshot, target = 0).
     */
    public function openOver30Days(Request $request)
    {
        $this->authorizeReports($request);
        $today = now();
        $cutoff = $today->copy()->subDays(30);

        $open = WorkOrder::query()->with('service_status')
            ->where('status', 'Open')
            ->get();

        $rows = $open->filter(fn (WorkOrder $wo) => $wo->created_date && Carbon::parse($wo->created_date)->lt($cutoff))
            ->sortBy('created_date')
            ->map(fn (WorkOrder $wo) => [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'description' => $wo->description,
                'location' => $wo->location,
                'status' => $wo->service_status?->name ?? $wo->status,
                'created_date' => $wo->created_date,
                'days' => (int) round(Carbon::parse($wo->created_date)->diffInDays($today)),
            ])->values();

        return $this->respond('open_over_30_days', [
            'title' => 'Open WOs Over 30 Days Old',
            'description' => 'Work orders that are still open and were created more than 30 days ago. Target is zero.',
            'hasMonthFilter' => false,
            'filters' => [],
            'total' => $open->count(),
            'breached' => $rows->count(),
            'countLabel' => 'No. of WOs',
            'columns' => [
                ['key' => 'work_order_no', 'label' => 'WO #', 'type' => 'wo_link'],
                ['key' => 'description', 'label' => 'Description', 'type' => 'truncate'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_date', 'label' => 'Created', 'type' => 'date'],
                ['key' => 'days', 'label' => 'Days open', 'type' => 'right'],
            ],
            'rows' => $rows,
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
