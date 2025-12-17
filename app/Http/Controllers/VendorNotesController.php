<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorNotesController extends Controller
{
    public function update(Request $request)
    {
        $validatedData = $request->validate([
            'cost_estimate' => 'required',
            'time_estimate' => 'nullable',
            'scheduled_end_date' => 'nullable',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $user = User::with('vendor')->find(auth()->id());
        $vendorId = $user->vendor->id;

        $propertywareServices = new PropertyWareService;

        DB::beginTransaction();
        try {

            $workOrder = WorkOrder::find($request->work_order_id);

            $workOrder->vendors()->updateExistingPivot($vendorId, [
                'cost_estimate' => $validatedData['cost_estimate'],
                'time_estimate' => $validatedData['time_estimate'],
                'scheduled_end_date' => $validatedData['scheduled_end_date'],
            ]);

            // Sync to PropertyWare with full details and approval status
            // $syncResult = $propertywareServices->updateWorkOrderVendorEstimates($workOrder, true, true);

            // if ($syncResult) {
            //     DB::commit();
            //     Log::info('Work order updated successfully!');
            // }

        } catch (\Exception $th) {
            DB::rollBack();
            Log::error('Vendor notes failed: '.$th->getMessage());
        }

        return redirect()->back();
    }
}
