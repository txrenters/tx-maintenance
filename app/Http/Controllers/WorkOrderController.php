<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\ServiceStatus;
use App\Models\Vendor;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $service_status = ServiceStatus::with(['work_orders.owner.user','work_orders.service_status','work_orders.vendors'])->get();

        // dd($service_status);

        $vendors = Vendor::select('id', 'name')->get();

        return inertia('WorkOrder/Index',[
            'title' => 'Work Orders',
            'service_status' => $service_status,
            'vendors' => $vendors,
            'filter' => $request->only(['search','per_page']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWorkOrderRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkOrder $workOrder)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WorkOrder $workOrder)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkOrder $workOrder)
    {
        //
    }
}
