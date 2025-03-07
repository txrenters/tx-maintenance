<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\ServiceStatus;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $workOrder->update($data);

        $propertyware = new PropertyWareService;
        

        $vendorIDsXml = "";

        if($request->service_status == 'New'){
            $vendorIDsXml .= "<vendorIDs xsi:type=\"soapenc:Array\" xmlns:soapenc=\"http://schemas.xmlsoap.org/soap/encoding/\">\n";
            foreach($request->vendors as $vendor){
                $vendorData = Vendor::select('id', 'propertyware_id')
                ->where('name', 'LIKE', "%{$vendor}%")
                ->first();
            
                DB::table('work_order_vendors')->insert([
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendorData->id
                ]);

                $vendorIDsXml .= "<vendorID xsi:type=\"xsd:long\">$vendorData->propertyware_id</vendorID>\n";
            }
            $vendorIDsXml .= "</vendorIDs>\n";
        }

        $propertyware->updateWorkOrder($request, $workOrder, $vendorIDsXml);


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
