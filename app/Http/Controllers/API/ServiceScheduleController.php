<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SendOwnerAppointmentNotificationJob;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServiceScheduleController extends Controller
{
    public function get_schedules(WorkOrder $workOrder)
    {
        $workOrder->load(['service_schedules.tenant', 'service_schedules.vendor', 'tenants', 'vendors.user']);

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
            'end_date' => 'nullable|date|after_or_equal:date',
            'description' => 'nullable|string',
            'vendor_id' => 'required|exists:vendors,id',
            'tenant_id' => 'nullable|exists:tenants,id',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        try {
            $serviceSchedule = ServiceSchedule::create([
                'title' => $validatedData['title'],
                'scheduled_date' => $validatedData['date'],
                'scheduled_end_date' => $validatedData['end_date'] ?? null,
                'description' => $validatedData['description'] ?? null,
                'work_order_id' => $validatedData['work_order_id'],
                'vendor_id' => $validatedData['vendor_id'],
                'tenant_id' => $validatedData['tenant_id'] ?? null,
            ]);

            // Sync to PropertyWare
            $this->syncScheduleToPropertyWare($serviceSchedule);

            // Only the vendor portal flags this, so the owner is notified when a
            // vendor sets the appointment — not when a coordinator sets it.
            if ($request->boolean('notify_owner_of_schedule')) {
                SendOwnerAppointmentNotificationJob::dispatch($serviceSchedule->id);
            }

            return redirect()->back()->with('success', 'Service scheduled successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to create service schedule', [
                'work_order_id' => $validatedData['work_order_id'] ?? null,
                'vendor_id' => $validatedData['vendor_id'] ?? null,
                'tenant_id' => $validatedData['tenant_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to set service schedule. Please try again.');
        }
    }

    /**
     * Update an existing service schedule.
     */
    public function update(Request $request, ServiceSchedule $serviceSchedule)
    {
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:date',
            'description' => 'nullable|string',
            'vendor_id' => 'required|exists:vendors,id',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        try {
            $serviceSchedule->update([
                'title' => $validatedData['title'],
                'scheduled_date' => $validatedData['date'],
                'scheduled_end_date' => $validatedData['end_date'] ?? null,
                'description' => $validatedData['description'] ?? null,
                'vendor_id' => $validatedData['vendor_id'],
                'tenant_id' => $validatedData['tenant_id'] ?? null,
            ]);

            // Sync to PropertyWare
            $this->syncScheduleToPropertyWare($serviceSchedule);

            // 303 so Inertia treats the follow-up request as a GET; these routes are
            // in the api middleware group, which lacks Inertia's automatic 302->303 conversion.
            return redirect()->back(303)->with('success', 'Service schedule updated successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to update service schedule', [
                'service_schedule_id' => $serviceSchedule->id,
                'work_order_id' => $serviceSchedule->work_order_id ?? null,
                'vendor_id' => $validatedData['vendor_id'] ?? null,
                'tenant_id' => $validatedData['tenant_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back(303)->with('error', 'Failed to update service schedule. Please try again.');
        }
    }

    /**
     * Sync service schedule to PropertyWare
     */
    private function syncScheduleToPropertyWare(ServiceSchedule $serviceSchedule): void
    {
        try {
            $workOrder = WorkOrder::with('vendors')->find($serviceSchedule->work_order_id);

            if (! $workOrder) {
                Log::warning('Work order not found for service schedule PropertyWare sync', [
                    'service_schedule_id' => $serviceSchedule->id,
                    'work_order_id' => $serviceSchedule->work_order_id,
                ]);

                return;
            }

            // Update the vendor's scheduled_end_date in the pivot table
            $workOrder->vendors()->updateExistingPivot($serviceSchedule->vendor_id, [
                'scheduled_end_date' => $serviceSchedule->scheduled_end_date ?? $serviceSchedule->scheduled_date,
            ]);

            // Update the work order's start_date and scheduled_end_date fields
            $workOrder->update([
                'start_date' => $serviceSchedule->scheduled_date,
                'scheduled_end_date' => $serviceSchedule->scheduled_end_date ?? $serviceSchedule->scheduled_date,
            ]);

            // Validate work order has required data for PropertyWare sync
            if (! $workOrder->location || trim($workOrder->location) === '') {
                Log::warning('Work order missing required location for PropertyWare sync', [
                    'service_schedule_id' => $serviceSchedule->id,
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                ]);

                return;
            }

            // $propertyWareService = new PropertyWareService;
            // $syncResult = $propertyWareService->updateWorkOrderServiceSchedule($workOrder);

            // if ($syncResult) {
            //     Log::info('Service schedule synced to PropertyWare successfully', [
            //         'service_schedule_id' => $serviceSchedule->id,
            //         'work_order_no' => $workOrder->work_order_no,
            //     ]);
            // } else {
            //     Log::warning('PropertyWare sync returned false for service schedule', [
            //         'service_schedule_id' => $serviceSchedule->id,
            //         'work_order_no' => $workOrder->work_order_no,
            //     ]);
            // }

        } catch (\Exception $e) {
            Log::error('Failed to sync service schedule to PropertyWare', [
                'service_schedule_id' => $serviceSchedule->id,
                'work_order_id' => $serviceSchedule->work_order_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

        if ($request->status == 'delete') {
            $serviceSchedule->delete();

            return redirect()->back();
        }

        $serviceSchedule->update($validatedData);

        // Sync to PropertyWare after status update
        $this->syncScheduleToPropertyWare($serviceSchedule);

        return redirect()->back();
    }

    /**
     * Delete a service schedule.
     */
    public function destroy(ServiceSchedule $serviceSchedule)
    {
        try {
            $workOrderId = $serviceSchedule->work_order_id;
            $vendorId = $serviceSchedule->vendor_id;

            // Delete the service schedule
            $serviceSchedule->delete();

            // Sync to PropertyWare after deletion
            $this->syncAfterDeletion($workOrderId, $vendorId);

            // 303 so Inertia treats the follow-up DELETE redirect as a GET (api group
            // lacks Inertia's automatic 302->303 conversion).
            return redirect()->back(303)->with('success', 'Service schedule deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to delete service schedule', [
                'service_schedule_id' => $serviceSchedule->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back(303)->with('error', 'Failed to delete service schedule. Please try again.');
        }
    }

    /**
     * Sync work order to PropertyWare after schedule deletion
     */
    private function syncAfterDeletion(int $workOrderId, int $vendorId): void
    {
        try {
            $workOrder = WorkOrder::with('vendors', 'service_schedules')->find($workOrderId);

            if (! $workOrder) {
                Log::warning('Work order not found after service schedule deletion', [
                    'work_order_id' => $workOrderId,
                ]);

                return;
            }

            // Find the latest remaining schedule for this vendor
            $latestSchedule = $workOrder->service_schedules()
                ->where('vendor_id', $vendorId)
                ->orderBy('scheduled_date', 'desc')
                ->first();

            // Update the vendor's scheduled_end_date in pivot table
            if ($latestSchedule) {
                $workOrder->vendors()->updateExistingPivot($vendorId, [
                    'scheduled_end_date' => $latestSchedule->scheduled_end_date ?? $latestSchedule->scheduled_date,
                ]);
            } else {
                // No more schedules for this vendor, clear the scheduled_end_date
                $workOrder->vendors()->updateExistingPivot($vendorId, [
                    'scheduled_end_date' => null,
                ]);
            }

            // Find the earliest and latest schedules across all vendors to update work order
            $earliestOverallSchedule = $workOrder->service_schedules()
                ->orderBy('scheduled_date', 'asc')
                ->first();

            $latestOverallSchedule = $workOrder->service_schedules()
                ->orderBy('scheduled_date', 'desc')
                ->first();

            // Update the work order's start_date and scheduled_end_date
            $workOrder->update([
                'start_date' => $earliestOverallSchedule ? $earliestOverallSchedule->scheduled_date : null,
                'scheduled_end_date' => $latestOverallSchedule ? ($latestOverallSchedule->scheduled_end_date ?? $latestOverallSchedule->scheduled_date) : null,
            ]);

            // Trigger PropertyWare sync
            $propertyWareService = new PropertyWareService;
            $syncResult = $propertyWareService->updateWorkOrderServiceSchedule($workOrder);

            if ($syncResult) {
                Log::info('Work order synced to PropertyWare after schedule deletion', [
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                ]);
            } else {
                Log::warning('PropertyWare sync failed after schedule deletion', [
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync to PropertyWare after schedule deletion', [
                'work_order_id' => $workOrderId,
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
