<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\ServiceStatus;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $year = $request->input('year', Carbon::now()->year);

        $workOrders = WorkOrder::whereYear('created_date', $year)->get();
        $tasks = WorkOrderTask::whereYear('created_at', $year)->get();
        $invoices = Invoice::all();

        $serviceStatus = ServiceStatus::withCount([
            'work_orders as work_orders_count' => function ($query) use ($year) {
                $query->whereYear('created_date', $year)
                    ->where('status', 'Open');
            },
        ])
            ->having('work_orders_count', '>', 0) // only get statuses with matching work orders
            ->where('name', '!=', 'Closed') // exclude closed statuses
            ->where('name', '!=', 'Not Changed')
            ->get()
            ->map(fn ($status) => [
                'name' => $status->name,
                'total' => $status->work_orders_count,
            ]);

        // Generate an array of all months (Jan to Dec)
        $months = collect([
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ]);

        // Query the database for work orders grouped by month
        $workOrderData = WorkOrder::select(
            DB::raw('MONTHNAME(created_date) as name'), // Month name
            DB::raw("SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as Completed"), // Completed count
            DB::raw("SUM(id) as Created") // Created count
        )
            ->whereYear('created_date', $year) // Filter for the selected year
            ->groupBy(DB::raw('MONTHNAME(created_date), MONTH(created_date)')) // Group by both month name and month number
            ->orderBy(DB::raw('MONTH(created_date)')) // Order by month number
            ->get();

        // Merge the results with the full list of months
        $workOrderChart = $months->map(function ($month) use ($workOrderData) {
            $data = $workOrderData->firstWhere('name', $month);

            return [
                'name' => $month, // Month name
                'Created' => $data->Created ?? 0, // Created work orders (default to 0 if no data)
                'Completed' => $data->Completed ?? 0, // Completed work orders (default to 0 if no data)
            ];
        });

        $vendors = Vendor::select('id', 'is_active')->get();

        return inertia('Dashboard', [
            'title' => 'Dashboard',
            'workOrders' => $workOrders,
            'tasks' => $tasks,
            'invoices' => $invoices,
            'vendors' => $vendors,
            'serviceStatus' => $serviceStatus,
            'workOrderChart' => $workOrderChart,
            'filter' => $request->only(['year']),
        ]);
    }
}
