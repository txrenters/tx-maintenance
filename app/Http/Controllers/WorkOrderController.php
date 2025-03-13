<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\WorkOrder;
use App\Jobs\UpdateWorkOrder;
use App\Models\ServiceStatus;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderController extends Controller
{
    protected $propertyWareServices;

    public function __construct(PropertyWareService $propertyWareServices){
        $this->propertyWareServices = $propertyWareServices;
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        
        $service_status = ServiceStatus::with([
            'work_order',
            'work_orders.service_status',
            'work_orders.vendors',
            'work_orders.requested_by',
            'work_orders.managed_by'
            ])
            ->filter(request(['search']))
            ->whereNot('name','Closed')
            ->whereNot('name','Not Change')
            ->get();

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
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $request->validated();

        $now = now();


        $data = [
            'is_emergency' => $request->is_emergency == 'Emergency' ? true : false,
            'category' => $request->category,
            'cost_estimate' => $request->cost_estimate,
            'hour_estimate' => $request->hour_estimate,
            'zone' => $request->zone,
            'end_date' => $request->end_date ? Carbon::parse($request->end_date)->toDateString() : null,
            'management_plan' => $request->management_plan,
            'closing_comments' =>  $request->closing_comments,
            'vendor_notes' => $request->vendor_notes,
            'additional_work_needed_reschedule' => $request->additional_work_needed_reschedule,
        ];


        DB::beginTransaction();
        try {

            $workOrder->update($data);

            $vendorIDsXml = "";

            $workOrder_tasks = WorkOrder::with(['tasks'])->find($workOrder->id)->where('');

            if($request->service_status == 'New' && $workOrder_tasks->tasks->count() == 0){

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

                
                $is_emergency = $request->is_emergency; 

                $task_template = TaskTemplate::with(['currentServiceStatus','tasks'])
                    ->whereHas('currentServiceStatus', function($q){
                        $q->where('name', 'New');
                    })
                    ->where('is_current_service_status_emergency', $is_emergency)
                    ->first();

                if(!empty($task_template->tasks)){
                    $tasks = [];

                    foreach($task_template->tasks as $task){
                        // if task is for work order coodinator, assigned a task to it
                        if($task->type == 'Woc'){
                            $assigned_user_id = User::role('woc')->first();
                            $tasks[] = [
                                'work_order_id' => $workOrder->id,
                                'assigned_user_id' => $assigned_user_id->id,
                                'task_id' => $task->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }else{
                            // if multiple vendor, assign task to every vendor, its okay they have the same task
                            foreach($request->vendors as $vendor){
                                $assigned_user_id = User::with('vendor')
                                    ->whereHas('vendor', function($q) use ($vendor){
                                        $q->where('name', 'LIKE', $vendor);
                                    })
                                    ->role('vendors')
                                    ->first();

                                $tasks[] = [
                                    'work_order_id' => $workOrder->id,
                                    'assigned_user_id' => $assigned_user_id->id,
                                    'task_id' => $task->id,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }
                        }
                    }

                    if(!empty($tasks)){
                        DB::table('work_order_tasks')->insert($tasks);
                    }
                }
            }
            UpdateWorkOrder::dispatch($data, $workOrder, $vendorIDsXml);
            
            DB::commit();
            Log::info('Work Order Updated', ['work_order_id' => $workOrder->id]);

        } catch (\Throwable $th) {
            Log::error('Work Order failed: ' . $th->getMessage(), [
                'exception' => $th->getTraceAsString()
            ]);
            DB::rollBack();
        }

        return redirect()->back();

    }

    public function closed_work_orders(Request $request)
    {
        $perPage = $request->per_page
         ? ($request->per_page == 'All' ? WorkOrder::count() : $request->per_page)
         : 10;

        $work_orders = WorkOrder::with([
                'service_status','requested_by'
            ])
            ->wherehas('service_status',function($q) {
                $q->where('name', 'Closed');
            })
            ->filter(request(['search']))
            ->orderBy('completed_date','DESC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($work_order) {
                return [
                    'id' => $work_order->id,
                    'work_order_no' => $work_order->work_order_no,
                    'location' => $work_order->location,
                    'completed_at' => $work_order->completed_date ? Carbon::parse($work_order->completed_date)->format('F d, Y') : null,
                    'requested_by' => $work_order->requested_by?->first_name.' '.$work_order->requested_by?->last_name,
                    'status' => $work_order->service_status->name == 'Closed' ? true : false,
                ];
            });

        return inertia('WorkOrder/Close',[
            'title' => 'Closed Work Orders',
            'work_orders' => $work_orders,
            'filter' => $request->only(['search','per_page']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function open(WorkOrder $workOrder)
    {        
        $service_status = ServiceStatus::where('name', 'New')->value('id');

        $openWorder =  $this->propertyWareServices->reOpenWorkOrder($workOrder);

        if($openWorder){
            $workOrder->update([
                'service_status_id' => $service_status,
                'completed_date' => null
            ]);
        }

        return redirect()->route('work_orders.closed_work_orders');
    }

    public function close(WorkOrder $workOrder)
    {
        $service_status = ServiceStatus::where('name', 'Closed')->value('id');

        $closeWorder =  $this->propertyWareServices->closeWorkOrder($workOrder);

        if($closeWorder){
            $workOrder->update([
                'service_status_id' => $service_status,
                'completed_date' => now()->toDateString()
            ]);
        }

        return redirect()->back();
    }
}


