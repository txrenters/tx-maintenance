<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Vendor Edit tab's "Update Work Order Details" form: the logged-in
 * vendor's cost / time estimate and target date on a work order.
 */
class VendorNotesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validatedData = $request->validate([
            'cost_estimate' => 'required|numeric|min:0',
            'time_estimate' => 'nullable|integer|min:0',
            'scheduled_end_date' => 'nullable|date',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $user = User::with('vendor')->find(auth()->id());
        $vendorId = $user->vendor->id;

        try {
            $workOrder = DB::transaction(function () use ($validatedData, $vendorId): WorkOrder {
                $workOrder = WorkOrder::query()->findOrFail($validatedData['work_order_id']);

                $workOrder->vendors()->updateExistingPivot($vendorId, [
                    'cost_estimate' => $validatedData['cost_estimate'],
                    'time_estimate' => $validatedData['time_estimate'] ?? null,
                    'scheduled_end_date' => $validatedData['scheduled_end_date'] ?? null,
                ]);

                return $workOrder;
            });

            // The estimate is what the office waits on before seeking the
            // owner's approval, so the assigned coordinator's bell rings. That
            // is the whole hand-off (Earl, 2026-10-20): nothing is pushed to
            // PropertyWare and the service status is the coordinator's to move.
            $this->ringEstimateBell(
                $workOrder,
                $user->vendor,
                (float) $validatedData['cost_estimate'],
                $validatedData['time_estimate'] ?? null,
            );
        } catch (\Throwable $th) {
            Log::error('Vendor notes failed: '.$th->getMessage());
        }

        return redirect()->back();
    }

    /**
     * The bell entry for a saved estimate - the same default-log activity
     * row the vendor message and the owner's approval use, so staff see it
     * in the notification feed and on the desktop without a new channel.
     */
    private function ringEstimateBell(WorkOrder $workOrder, Vendor $vendor, float $cost, int|string|null $hours): void
    {
        $hours = $hours !== null && $hours !== '' ? (int) $hours : null;
        $message = $vendor->name.' estimated $'.number_format($cost, 2)
            .($hours !== null ? ' ('.$hours.' hrs)' : '').'.';

        activity()
            ->performedOn($workOrder)
            ->event('vendor_estimate_submitted')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'vendor_id' => $vendor->id,
                'cost_estimate' => $cost,
                'time_estimate' => $hours,
                'message' => $message,
                'source' => 'vendor_edit',
                'read' => false,
            ])
            ->log('Work Order #'.$workOrder->work_order_no.' - Vendor Estimate Submitted');
    }
}
