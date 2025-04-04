<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

class ServiceScheduleController extends Controller
{
    public function get_schedules(WorkOrder $workOrder)
    {
        $workOrder->load(['service_schedules.tenant', 'service_schedules.vendor', 'tenants', 'vendors']);

        return response()->json($workOrder, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'time' => 'required',
            'description' => 'nullable|string',
            'vendor_id' => 'required|exists:vendors,id',
            'tenant_id' => 'required|exists:tenants,id',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        try {
            ServiceSchedule::create([
                'title' => $validatedData['title'],
                'scheduled_date' => $validatedData['date'].' '.$validatedData['time'],
                'description' => $validatedData['description'] ?? null,
                'work_order_id' => $validatedData['work_order_id'],
                'vendor_id' => $validatedData['vendor_id'],
                'tenant_id' => $validatedData['tenant_id'],
            ]);

            return redirect()->back()->with('success', 'Service scheduled successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to set service schedule. Please try again.');
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update_status(Request $request, ServiceSchedule $serviceSchedule)
    {
        $validatedData = $request->validate([
            'status' => 'required',
        ]);

        $serviceSchedule->update($validatedData);

        return redirect()->back();
    }
}
