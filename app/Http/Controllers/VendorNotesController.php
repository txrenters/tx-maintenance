<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorNotes;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorNotesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getNotes(WorkOrder $workOrder)
    {
        $workOrder->load(['vendors','vendor_notes.vendor']);
    
        return response()->json($workOrder, 200);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required',
            'description' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $user = User::with('vendor')->find(auth()->id());
        $validatedData['vendor_id'] = $user->vendor->id;

        $propertywareServices = new PropertyWareService();

        DB::beginTransaction();
        try {
            $created = VendorNotes::create($validatedData);
            $note = $propertywareServices->addVendorNotes($created);
            if($note){
                DB::commit();
                Log::info('Vendor notes created successfully!');
            }
            
        } catch (\Exception $th) {
            //throw $th;
            DB::rollBack();
            Log::error('Vendor notes failed: '. $th->getMessage());
        }

        return redirect()->back();
    }

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

        $propertywareServices = new PropertyWareService();

        DB::beginTransaction();
        try {

            $workOrder = WorkOrder::find($request->work_order_id);

            $workOrder->vendors()->updateExistingPivot($vendorId, [
                'cost_estimate' => $validatedData['cost_estimate'],
                'time_estimate' => $validatedData['time_estimate'],
                'scheduled_end_date' => $validatedData['scheduled_end_date'],
            ]);

            $note = $propertywareServices->updateWorkOrderDetails($workOrder);

            if($note){
                DB::commit();
                Log::info('Work order updated successfully!');
            }
            
        } catch (\Exception $th) {
            DB::rollBack();
            Log::error('Vendor notes failed: '. $th->getMessage());
        }

        return redirect()->back();
    }

    public function destroy(VendorNotes $vendorNotes)
    {
        $vendorNotes->delete();

        return redirect()->back();
    }
}
