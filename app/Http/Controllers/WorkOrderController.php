<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Jobs\UpdateWorkOrder;
use App\Models\ServiceStatus;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $service_status = ServiceStatus::with([
            'work_orders.service_status',
            'work_orders.vendors',
            'work_orders.requested_by',
            'work_orders.managed_by'
        ])->get();

        $categories = DB::table('work_orders')->select('category')->orderBy('category')->distinct()->get();

        $vendors = DB::table('vendors')->select('id','name')->orderBy('name')->get();

        return inertia('WorkOrder/Index',[
            'title' => 'Work Orders',
            'service_status' => $service_status,
            'vendors' => $vendors,
            'categories' => $categories,
            'filter' => $request->only(['search','per_page']),
        ]);
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkOrder $workOrder)
    {
        $now = now();

        $request->validate([
            'work_order_no' => 'required'
        ]);

        $data = [
            'is_emergency' => $request->is_emergency == 'Emergency' ? true : false,
            'category' => $request->category,
            'cost_estimate' => $request->cost_estimate,
            'hour_estimate' => $request->hour_estimate,
            'zone' => $request->zone,
            'end_date' => Carbon::parse($request->end_date)->format('Y-m-d H:i:s'),
            'management_plan' => $request->management_plan,
            'closing_comments' =>  $request->closing_comments,
            'vendor_notes' => $request->vendor_notes,
            'additional_work_needed_reschedule' => $request->additional_work_needed_reschedule,
        ];


        DB::beginTransaction();
        try {

            $workOrder->update($data);

            $vendorIDsXml = "";

            if($request->service_status == 'New'){
                DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->delete();
                $vendorIDsXml .= "<vendorIDs xsi:type=\"soapenc:Array\" xmlns:soapenc=\"http://schemas.xmlsoap.org/soap/encoding/\">\n";
                foreach($request->vendors as $vendor){
                    $vendorData = Vendor::select('id', 'propertyware_id')
                    ->where('name', 'LIKE', "%{$vendor}%")
                    ->first();
                
                    DB::table('work_order_vendors')->insert([
                        'work_order_id' => $workOrder->id,
                        'vendor_id' => $vendorData->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $vendorIDsXml .= "<vendorID xsi:type=\"xsd:long\">$vendorData->propertyware_id</vendorID>\n";
                }
                $vendorIDsXml .= "</vendorIDs>\n";
            }

            UpdateWorkOrder::dispatch($data, $workOrder, $vendorIDsXml);
            
            DB::commit();
            Log::info('Work Order Updated', ['work_order_id' => $workOrder->id]);

        } catch (\Throwable $th) {
           Log::error('Work Order failed: '.$th);
           DB::rollBack();
        }
        return redirect()->back();

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkOrder $workOrder)
    {
        //
    }
}
