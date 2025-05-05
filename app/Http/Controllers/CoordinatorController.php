<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CoordinatorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $work_orders = WorkOrder::with([
            'service_status', 'requested_by', 'woc',
        ])
            ->whereHas('service_status', function ($query) {
                $query->where('status', 'Open');
            })
            ->filter(request(['search']))
            ->orderBy('created_date', 'DESC')
            ->paginate(50)
            ->withQueryString()
            ->through(function ($work_order) {
                return [
                    'id' => $work_order->id,
                    'work_order_no' => $work_order->work_order_no,
                    'location' => $work_order->location,
                    'created_date' => $work_order->created_date ? Carbon::parse($work_order->created_date)->format('F d, Y') : null,
                    'coordinator' => $work_order->woc?->name,
                    'coordinator_id' => $work_order->woc?->id,
                    'status' => $work_order->service_status->name,
                ];
            });

        $coordinators = User::role('woc')->get();

        return inertia('WorkOrder/Coordinators', [
            'title' => 'Work Orders',
            'work_orders' => $work_orders,
            'coordinators' => $coordinators,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'id' => 'required',
            'coordinator_id' => 'required',
        ]);

        $workOrder->update(['user_id' => $request->coordinator_id]);

        return redirect()->back();
    }
}
