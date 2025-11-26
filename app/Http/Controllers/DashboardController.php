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
        $year = $request->input('year', Carbon::now()->year);

        return Inertia::render('Dashboard', [
            'title' => 'Dashboard',
            'stats' => $this->getEssentialStats($year),
            'filter' => $request->only(['year']),
            'workOrderChart' => $this->getWorkOrderChart($year),
            'serviceStatus' => $this->getServiceStatus($year),
            'inspectionAnalytics' => $this->getInspectionAnalytics($year),
        ]);
    }

    private function getEssentialStats($year)
    {
        // Use efficient aggregate queries instead of loading full collections
        $workOrderStats = WorkOrder::selectRaw('
                COUNT(*) as total_work_orders,
                COUNT(CASE WHEN status = "Closed" THEN 1 END) as completed_work_orders,
                COUNT(CASE WHEN status = "Open" AND service_status_id = 1 THEN 1 END) as pending_work_orders,
                COUNT(CASE WHEN status = "Open" AND service_status_id != 1 THEN 1 END) as process_work_orders,
                COUNT(CASE WHEN priority IN ("urgent", "high") THEN 1 END) as urgent_work_orders
            ')
            ->scoped()
            ->whereYear('created_date', $year)
            ->first();

        $taskStats = WorkOrderTask::selectRaw('
                COUNT(*) as total_tasks,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_tasks
            ')
            ->scoped()
            ->whereYear('created_at', $year)
            ->first();

        $inspectionStats = Jobber::selectRaw('
                COUNT(*) as total_inspections,
                COUNT(CASE WHEN job_status NOT IN ("archived", "closed", "completed", "cancelled", "done") THEN 1 END) as active_inspections
            ')
            ->whereYear('created_at', $year)
            ->first();

        $visitStats = JobberVisit::selectRaw('
                COUNT(CASE WHEN is_complete = 1 THEN 1 END) as completed_inspection_visits,
                COUNT(CASE WHEN is_complete = 0 AND start_at < NOW() THEN 1 END) as overdue_inspections,
                COUNT(CASE WHEN is_complete = 0 AND start_at >= NOW() THEN 1 END) as upcoming_inspections
            ')
            ->whereYear('start_at', $year)
            ->first();

        // Calculate monthly growth rate efficiently
        $currentMonth = now()->month;
        $lastMonth = $currentMonth > 1 ? $currentMonth - 1 : 12;
        $currentYear = now()->year;
        $lastMonthYear = $currentMonth > 1 ? $currentYear : $currentYear - 1;

        $thisMonthOrders = WorkOrder::scoped()
            ->whereMonth('created_date', $currentMonth)
            ->whereYear('created_date', $currentYear)
            ->count();

        $lastMonthOrders = WorkOrder::scoped()
            ->whereMonth('created_date', $lastMonth)
            ->whereYear('created_date', $lastMonthYear)
            ->count();

        $monthlyGrowthRate = $lastMonthOrders > 0 ?
            (($thisMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100 : 100;

        // Calculate average completion time efficiently
        $completionStats = WorkOrder::selectRaw('
                AVG(DATEDIFF(completed_date, created_date)) as avg_completion_days
            ')
            ->scoped()
            ->whereYear('created_date', $year)
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
        $months = collect([
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ]);

        $workOrderData = WorkOrder::selectRaw('
                MONTHNAME(created_date) as name,
                SUM(CASE WHEN status = "Closed" THEN 1 ELSE 0 END) as Completed,
                COUNT(id) as Created
            ')
            ->scoped()
            ->whereYear('created_date', $year)
            ->groupByRaw('MONTHNAME(created_date), MONTH(created_date)')
            ->orderByRaw('MONTH(created_date)')
            ->get()
            ->keyBy('name');

        return $months->map(function ($month) use ($workOrderData) {
            $data = $workOrderData->get($month);

            return [
                'name' => $month,
                'Created' => $data?->Created ?? 0,
                'Completed' => $data?->Completed ?? 0,
            ];
        });
    }

    private function getServiceStatus($year)
    {
        return ServiceStatus::withCount([
            // Count all related work orders for this service status
            'work_orders as total' => function ($q) use ($year) {
                // Apply your WorkOrderScope and filters automatically
                $q->filtered() // If you have a local scope named filtered()
                    ->scoped()   // If you have a local/global scope named scoped()
                    ->whereYear('created_date', $year)
                    ->where('work_orders.status', 'Open');
            },
        ])
            ->whereNotIn('name', ['Not Changed', 'Closed'])
            ->having('total', '>', 0)
            ->get()
            ->map(fn ($status) => [
                'name' => $status->name,
                'total' => $status->total,
            ]);
    }

    private function getInspectionAnalytics($year)
    {
        // Return minimal inspection analytics for charts
        $inspectionsByDayOfWeek = JobberVisit::selectRaw('
                DAYOFWEEK(start_at) - 1 as day_of_week,
                COUNT(*) as count
            ')
            ->whereYear('start_at', $year)
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
            'completionRate' => $this->getInspectionCompletionRate($year),
        ];
    }

    private function getInspectionCompletionRate($year)
    {
        $stats = JobberVisit::selectRaw('
                COUNT(*) as total_visits,
                COUNT(CASE WHEN is_complete = 1 THEN 1 END) as completed_visits
            ')
            ->whereYear('start_at', $year)
            ->first();

        if ($stats->total_visits == 0) {
            return 0;
        }

        return round(($stats->completed_visits / $stats->total_visits) * 100);
    }
}
