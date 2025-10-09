<?php

namespace App\Http\Controllers;

use App\Events\WorkOrderUpdated;
use App\Exports\WorkOrdersExport;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Jobs\UpdateWorkOrder;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use App\Services\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class WorkOrderController extends Controller
{
    protected $propertyWareServices;

    public function __construct(PropertyWareService $propertyWareServices)
    {
        $this->propertyWareServices = $propertyWareServices;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        $service_status = ServiceStatus::with([
            'work_order',
            'work_order.owners',
            'work_orders' => function ($query) {
                $query->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                        $date = request()->only(['start_date', 'end_date']);
                        $start_date = Carbon::parse($date['start_date'])->startOfDay();
                        $end_date = Carbon::parse($date['end_date'])->endOfDay();

                        $q->whereBetween('created_date', [$start_date, $end_date]);
                    })
                    ->whereNot('status', 'Closed');
            },
            'work_orders.service_status',
            'work_orders.vendors',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->whereNot('status', 'Closed')
            ->whereNot('name', 'Not Changed')
            ->get();

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();

        $vendors = DB::table('vendors')->select('id', 'name')->where('is_active', true)->orderBy('name')->get();

        $users = User::role(['woc', 'admin'])->get();

        return inertia('WorkOrder/Index', [
            'title' => 'Work Orders',
            'service_status' => $service_status,
            'vendors' => $vendors,
            'categories' => $categories,
            'users' => $users,
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function show(WorkOrder $workOrder)
    {
        $workOrder->load([
            'service_status',
            'vendors',
            'requested_by',
            'managed_by',
            'woc.wocNumber.twilioPhoneNumber',
            'owners',
        ])->first();

        return response()->json($workOrder, 200);
    }

    public function details(WorkOrder $workOrder)
    {
        //  dd($workOrder);
        $workOrder->load([
            'service_status',
            'requested_by',
            'managed_by',
            'woc.wocNumber.twilioPhoneNumber',
            'owners',
            'tasks',
            'notes.user',
            'attachments',
            'invoices',
            'tenant_conversation',
            'owner_conversation',
            'vendor_conversation',
            'vendor_tenant_conversation',
        ]);

        // Get all conversations for this work order
        $conversations = collect()
            ->merge($workOrder->tenant_conversation)
            ->merge($workOrder->owner_conversation)
            ->merge($workOrder->vendor_conversation)
            ->merge($workOrder->vendor_tenant_conversation)
            ->sortByDesc('created_at');

        // Get service statuses for potential updates
        $serviceStatuses = ServiceStatus::all();

        // Get vendors for potential assignments
        $vendors = Vendor::where('is_active', true)->get();


        return inertia('WorkOrder/Show', [
            'title' => 'Work Order #'.$workOrder->work_order_no,
            'workOrder' => $workOrder,
            'conversations' => $conversations->values(),
            'tasks' => $workOrder->tasks,
            'invoices' => $workOrder->invoices,
            'notes' => $workOrder->notes,
            'attachments' => $workOrder->attachments,
            'vendors' => $vendors,
            'serviceStatuses' => $serviceStatuses,
        ]);
    }

    public function report(WorkOrder $workOrder)
    {
        $workOrder->load([
            'service_status',
            'vendors',
            'requested_by',
            'managed_by',
            'woc.wocNumber.twilioPhoneNumber',
            'tasks.assigned_user',
            'notes',
            'invoices',
            'attachments',
            'service_schedules',
            'tenant_conversation',
            'owner_conversation',
            'vendor_conversation',
            'vendor_tenant_conversation',
        ]);

        return inertia('WorkOrder/Report', [
            'title' => 'Report Summary',
            'work_order' => $workOrder,
        ]);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $validatedData = $request->validated();

        try {
            $workOrder->update($validatedData);

            UpdateWorkOrder::dispatch($workOrder->id, $validatedData);

            // Broadcast the work order update
            $workOrder->load('service_status');

            Log::info('Work Order Update Dispatched', ['work_order_no' => $workOrder->work_order_no]);

            return redirect()->back()->with('success', 'Work order update has been queued.');

        } catch (\Throwable $th) {
            Log::error('Work Order update failed: '.$th->getMessage(), [
                'work_order_id' => $workOrder->id,
                'exception' => $th->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to queue work order update.');
        }

        return redirect()->back()->with('error', 'Failed to queue work order update.');

    }

    public function closed_work_orders(Request $request)
    {
        $perPage = $request->per_page
         ? ($request->per_page == 'All' ? WorkOrder::count() : $request->per_page)
         : 10;

        $work_orders = WorkOrder::with([
            'service_status', 'requested_by',
        ])
            ->whereHas('service_status', function ($q) {
                $q->where('name', 'Closed');
            })
            ->filter(request(['search']))
            ->orderBy('completed_date', 'DESC')
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

        return inertia('WorkOrder/Close', [
            'title' => 'Closed Work Orders',
            'work_orders' => $work_orders,
            'filter' => $request->only(['search', 'per_page']),
        ]);
    }

    public function vendor_change(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'vendors' => 'required|array',
        ]);

        DB::beginTransaction();

        try {
            $vendorIDsXml = '';
            $vendorIds = [];

            DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->delete();

            $vendorIDsXml = '<vendorIDs xsi:type="soapenc:Array" xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">';

            foreach ($request->vendors as $vendor) {
                $vendorData = Vendor::whereLike('name', "%{$vendor}%")->first();
                $vendorIDsXml .= "<vendorID xsi:type=\"xsd:long\">{$vendorData->propertyware_id}</vendorID>";
                $vendorIds[] = $vendorData->id;

                DB::table('work_order_vendors')->insert([
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendorData->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $vendorIDsXml .= '</vendorIDs>';

            $this->propertyWareServices->changeWorkOrderVendors($workOrder, $vendorIDsXml);

            $workOrder->update([
                'local_status' => 'Updated',
            ]);

            DB::commit();
            return redirect()->back()->with('success', 'Work order vendors updated successfully.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Work order vendors failed.'.$th->getMessage());
        }
    }

    public function emergency_change(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'is_emergency' => 'nullable|string',
        ]);

        $isEmergency = $request->is_emergency == 'Emergency';

        $workOrder->update(['is_emergency' => $isEmergency]);

        $serviceStatusId = 1; // actual ID for 'New'
        $service_status = ServiceStatus::find($serviceStatusId);

        WorkOrderTask::where('work_order_id', $workOrder->id)->delete();

        TaskService::createTasksForWorkOrder($workOrder, $isEmergency, $serviceStatusId);

        $propertyWare = new PropertyWareService;
        $propertyWare->updateServiceStatus($workOrder, $service_status);

        // Broadcast the work order update
        $workOrder->load('service_status');
        event(new WorkOrderUpdated($workOrder));

        return redirect()->back()->with('success', 'Work order emergency status updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function open(WorkOrder $workOrder)
    {
        $service_status = ServiceStatus::where('name', 'New')->value('id');

        $openWorder = $this->propertyWareServices->reOpenWorkOrder($workOrder);

        if ($openWorder) {
            $workOrder->update([
                'service_status_id' => $service_status,
                'status' => 'Open',
                'local_status' => 'Created',
                'completed_date' => null,
            ]);

            // Broadcast the work order update
            $workOrder->load('service_status');
            event(new WorkOrderUpdated($workOrder));
        }

        return redirect()->route('work_orders.closed_work_orders');
    }

    public function close(WorkOrder $workOrder)
    {
        $service_status = ServiceStatus::where('name', 'Closed')->value('id');

        $conversation_url = route('conversation.show', $workOrder->id);

        $closeWorder = $this->propertyWareServices->closeWorkOrder($workOrder, $conversation_url);

        if ($closeWorder) {
            $workOrder->update([
                'status' => 'Closed',
                'service_status_id' => $service_status,
                'completed_date' => now()->toDateString(),
            ]);

            $workOrder->tasks()->each(function ($task) {
                $task->delete();
            });

            // Broadcast the work order update
            $workOrder->load('service_status');
        }

        return redirect()->back();
    }

    public function destroy(WorkOrder $workOrder)
    {
        $workOrder->delete();

        return response()->json([
            'message' => 'Work order deleted successfully.',
        ], 200);
    }

    public function import(Request $request)
    {
        $request->validate([
            'work_order_no' => 'required|integer',
        ]);
        $propertyWare = new PropertyWareService;
        $workOrders = ''; // Initialize as an array to store multiple work orders

        $work_order_no = $request->work_order_no;

        $work_order_no = (int) $work_order_no;
        $workOrders = $propertyWare->getWorkOrderByNumber($work_order_no);

        $importWorkOrder = new WorkOrderService;
        $importWorkOrder->handle($workOrders);

        return redirect()->back()->with('success', 'Work orders updated successfully.');
    }

    public function export()
    {
        return Excel::download(new WorkOrdersExport, 'Work_Orders_Export_'.date('d-m-Y-h-i').'.xlsx');
    }
}
