<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberVisit;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // Redirect accounting users to waiting on payments page
        if (auth()->user()->hasRole('accounting')) {
            return redirect()->route('work_orders.waiting_on_payment');
        }

        $year = $request->input('year', Carbon::now()->year);

        return Inertia::render('Dashboard', [
            'title' => 'Dashboard',
            'stats' => $this->getEssentialStats(),
            'filter' => $request->only(['year']),
            'workOrderChart' => $this->getWorkOrderChart($year),
            'serviceStatus' => $this->getServiceStatus(),
            'inspectionAnalytics' => $this->getInspectionAnalytics(),
        ]);
    }

    private function getEssentialStats()
    {
        // Use efficient aggregate queries instead of loading full collections
        $workOrderStats = WorkOrder::selectRaw('
                COUNT(*) as total_work_orders,
                COUNT(CASE WHEN status = "Closed" THEN 1 END) as completed_work_orders,
                COUNT(CASE WHEN status = "Open" AND service_status_id = 1 THEN 1 END) as pending_work_orders,
                COUNT(CASE WHEN status = "Open" AND service_status_id != 1 THEN 1 END) as process_work_orders,
                COUNT(CASE WHEN status = "Open" AND priority IN ("urgent", "high") THEN 1 END) as urgent_work_orders
            ')
            ->scoped()
            ->taggedForVendor()
            ->whereIn('status', ['Open', 'Closed'])
            ->first();

        $taskStats = WorkOrderTask::selectRaw('
                COUNT(*) as total_tasks,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_tasks
            ')
            ->first();

        $inspectionStats = Jobber::selectRaw('
                COUNT(*) as total_inspections,
                COUNT(CASE WHEN closed_at IS NULL AND job_status NOT IN ("archived", "closed", "completed", "cancelled", "done") THEN 1 END) as active_inspections
            ')
            ->first();

        $now = now();
        $visitStats = JobberVisit::selectRaw('
                COUNT(CASE WHEN is_complete = 1 THEN 1 END) as completed_inspection_visits,
                COUNT(CASE WHEN is_complete = 0 AND start_at < ? THEN 1 END) as overdue_inspections,
                COUNT(CASE WHEN is_complete = 0 AND start_at >= ? THEN 1 END) as upcoming_inspections
            ', [$now, $now])
            ->first();

        // Calculate monthly growth rate efficiently
        $currentMonth = now()->month;
        $lastMonth = $currentMonth > 1 ? $currentMonth - 1 : 12;
        $currentYear = now()->year;
        $lastMonthYear = $currentMonth > 1 ? $currentYear : $currentYear - 1;

        $thisMonthOrders = WorkOrder::scoped()
            ->taggedForVendor()
            ->whereMonth('created_date', $currentMonth)
            ->whereYear('created_date', $currentYear)
            ->count();

        $lastMonthOrders = WorkOrder::scoped()
            ->taggedForVendor()
            ->whereMonth('created_date', $lastMonth)
            ->whereYear('created_date', $lastMonthYear)
            ->count();

        $monthlyGrowthRate = $lastMonthOrders > 0 ?
            (($thisMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100 : 100;

        // Calculate average completion time efficiently
        $dateDiffExpr = $this->sqlDateDiffDays('completed_date', 'created_date');
        $completionStats = WorkOrder::selectRaw("
                AVG({$dateDiffExpr}) as avg_completion_days
            ")
            ->scoped()
            ->taggedForVendor()
            ->whereNotNull('completed_date')
            ->where('completed_date', '>', DB::raw('created_date'))
            ->first();

        return [
            'total_work_orders' => $workOrderStats->total_work_orders,
            'completed_work_orders' => $workOrderStats->completed_work_orders,
            'pending_work_orders' => $workOrderStats->pending_work_orders,
            'process_work_orders' => $workOrderStats->process_work_orders,
            'urgent_work_orders' => $workOrderStats->urgent_work_orders,
            'completed_tasks' => $taskStats->completed_tasks,
            'total_tasks' => $taskStats->total_tasks,
            'incomplete_tasks' => $taskStats->total_tasks - $taskStats->completed_tasks,
            'total_inspections' => $inspectionStats->total_inspections,
            'active_inspections' => $inspectionStats->active_inspections,
            'completed_inspection_visits' => $visitStats->completed_inspection_visits,
            'overdue_inspections' => $visitStats->overdue_inspections,
            'upcoming_inspections' => $visitStats->upcoming_inspections,
            'monthly_work_orders' => $thisMonthOrders,
            'monthly_growth_rate' => round($monthlyGrowthRate, 1),
            'average_completion_time' => round($completionStats->avg_completion_days ?? 0),
        ];
    }

    private function getWorkOrderChart($year)
    {
        $currentYear = Carbon::now()->year;
        $months = collect();

        // If current year or no filter, show rolling 12 months
        if ($year == $currentYear) {
            $startDate = Carbon::now()->subMonths(11)->startOfMonth();

            for ($i = 0; $i < 12; $i++) {
                $date = $startDate->copy()->addMonths($i);
                $months->push([
                    'month' => $date->month,
                    'year' => $date->year,
                    'label' => $date->format('M Y'), // e.g., "Feb 2025"
                ]);
            }

            // Query work orders for the last 12 months
            $monthExpr = $this->sqlMonth('created_date');
            $yearExpr = $this->sqlYear('created_date');
            $workOrderData = WorkOrder::selectRaw("
                    {$monthExpr} as month,
                    {$yearExpr} as year,
                    SUM(CASE WHEN status = \"Closed\" THEN 1 ELSE 0 END) as Completed,
                    COUNT(id) as Created
                ")
                ->scoped()
                ->taggedForVendor()
                ->where('created_date', '>=', $startDate)
                ->groupByRaw("{$yearExpr}, {$monthExpr}")
                ->get()
                ->keyBy(function ($item) {
                    return $item->year.'-'.$item->month;
                });

            return $months->map(function ($monthData) use ($workOrderData) {
                $key = $monthData['year'].'-'.$monthData['month'];
                $data = $workOrderData->get($key);

                return [
                    'name' => $monthData['label'],
                    'Created' => $data?->Created ?? 0,
                    'Completed' => $data?->Completed ?? 0,
                ];
            });
        }

        // For past years, show all 12 months of that specific year
        $monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        $monthNameExpr = $this->sqlMonthName('created_date');
        $monthExpr = $this->sqlMonth('created_date');
        $workOrderData = WorkOrder::selectRaw("
                {$monthNameExpr} as name,
                SUM(CASE WHEN status = \"Closed\" THEN 1 ELSE 0 END) as Completed,
                COUNT(id) as Created
            ")
            ->scoped()
            ->taggedForVendor()
            ->whereYear('created_date', $year)
            ->groupByRaw("{$monthNameExpr}, {$monthExpr}")
            ->orderByRaw("{$monthExpr}")
            ->get()
            ->keyBy('name');

        return collect($monthNames)->map(function ($month) use ($workOrderData) {
            $data = $workOrderData->get($month);

            return [
                'name' => $month,
                'Created' => $data?->Created ?? 0,
                'Completed' => $data?->Completed ?? 0,
            ];
        });
    }

    private function getServiceStatus()
    {
        return ServiceStatus::withCount([
            'work_orders as total' => function ($q) {
                $q->filtered()
                    ->scoped()
                    ->taggedForVendor()
                    ->where('work_orders.status', 'Open');
            },
        ])
            ->whereNotIn('name', ['Not Changed', 'Closed'])
            ->get()
            ->filter(fn ($status) => $status->total > 0)
            ->values()
            ->map(fn ($status) => [
                'name' => $status->name,
                'total' => $status->total,
            ]);
    }

    private function getInspectionAnalytics()
    {
        // Return minimal inspection analytics for charts
        $dayOfWeekExpr = $this->sqlDayOfWeekZeroIndexed('start_at');
        $inspectionsByDayOfWeek = JobberVisit::selectRaw("
                {$dayOfWeekExpr} as day_of_week,
                COUNT(*) as count
            ")
            ->groupBy('day_of_week')
            ->get()
            ->keyBy('day_of_week');

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $inspectionsByDay = collect($days)->map(function ($day, $index) use ($inspectionsByDayOfWeek) {
            return [
                'name' => substr($day, 0, 3),
                'inspections' => $inspectionsByDayOfWeek->get($index)?->count ?? 0,
            ];
        });

        return [
            'inspectionsByDayOfWeek' => $inspectionsByDay,
            'completionRate' => $this->getInspectionCompletionRate(),
        ];
    }

    private function getInspectionCompletionRate()
    {
        $stats = JobberVisit::selectRaw('
                COUNT(*) as total_visits,
                COUNT(CASE WHEN is_complete = 1 THEN 1 END) as completed_visits
            ')
            ->first();

        if ($stats->total_visits == 0) {
            return 0;
        }

        return round(($stats->completed_visits / $stats->total_visits) * 100);
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function sqlYear(string $column): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%Y', {$column}) AS INTEGER)"
            : "YEAR({$column})";
    }

    private function sqlMonth(string $column): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%m', {$column}) AS INTEGER)"
            : "MONTH({$column})";
    }

    private function sqlMonthName(string $column): string
    {
        if (! $this->isSqlite()) {
            return "MONTHNAME({$column})";
        }

        $monthExpr = $this->sqlMonth($column);

        return "CASE {$monthExpr}"
            ." WHEN 1 THEN 'January'"
            ." WHEN 2 THEN 'February'"
            ." WHEN 3 THEN 'March'"
            ." WHEN 4 THEN 'April'"
            ." WHEN 5 THEN 'May'"
            ." WHEN 6 THEN 'June'"
            ." WHEN 7 THEN 'July'"
            ." WHEN 8 THEN 'August'"
            ." WHEN 9 THEN 'September'"
            ." WHEN 10 THEN 'October'"
            ." WHEN 11 THEN 'November'"
            ." WHEN 12 THEN 'December'"
            .' END';
    }

    private function sqlDayOfWeekZeroIndexed(string $column): string
    {
        return $this->isSqlite()
            ? "CAST(strftime('%w', {$column}) AS INTEGER)"
            : "(DAYOFWEEK({$column}) - 1)";
    }

    private function sqlDateDiffDays(string $newer, string $older): string
    {
        return $this->isSqlite()
            ? "(julianday({$newer}) - julianday({$older}))"
            : "DATEDIFF({$newer}, {$older})";
    }
}
