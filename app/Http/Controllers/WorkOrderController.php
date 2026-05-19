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
use App\Notifications\NewWorkOrderAssignNotification;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use App\Services\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $query = ServiceStatus::with([
            'work_order',
            'work_order.owners',
            'work_orders' => function ($q) {
                $q->scoped()
                    // Apply search filter
                    ->when(request('search'), function ($query, $search) {
                        $query->where('work_order_no', $search);
                    })
                    // Apply vendor filter
                    ->when(request('vendor'), function ($query, $vendorId) {
                        $query->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    // Apply date range filter
                    ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                        $date = request()->only(['start_date', 'end_date']);
                        $start = Carbon::parse($date['start_date'])->startOfDay();
                        $end = Carbon::parse($date['end_date'])->endOfDay();
                        $query->whereBetween('created_date', [$start, $end]);
                    })
                    ->where('status', 'Open')
                    ->where('category', 'NOT LIKE', '%move out inspection%')
                    ->where('type', 'NOT LIKE', '%Biweekly Lawn Services%')
                    ->where('type', 'NOT LIKE', '%Turnover%');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->whereNot('name', 'Not Changed');

        $service_status = $query->get();

        // Remove statuses that should be at the end
        $waitingOnBillStatus = $service_status->firstWhere('name', 'Completed - Verified - Waiting on Bill');
        $waitingOnPaymentStatus = $service_status->firstWhere('name', 'Approved - Waiting on Payment');
        $closedStatus = $service_status->firstWhere('name', 'Closed');

        $service_status = $service_status->reject(fn ($status) => in_array($status->name, [
            'Completed - Verified - Waiting on Bill',
            'Approved - Waiting on Payment',
            'Closed',
        ]));

        // Add "Paid" service status with work orders that have payment (total_cost not 0) within 30 days
        $paidStatus = ServiceStatus::where('name', 'Paid')->first();
        if ($paidStatus) {
            $paidWorkOrders = WorkOrder::query()
                ->scoped()
                ->with(['service_status', 'vendors', 'requested_by', 'managed_by', 'tasks', 'owners'])
                // Apply search filter
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                // Apply vendor filter
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                // Apply date range filter
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                // Work orders with non-zero total_cost completed within 30 days
                ->whereNotNull('total_cost')
                ->where('total_cost', '>', 0)
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(30))
                ->where('category', 'NOT LIKE', '%lawn care%')
                ->latest('completed_date')
                ->get();

            // Add the paid work orders to the Paid status
            $paidStatus->setRelation('work_orders', $paidWorkOrders);
        }

        // Add "Closed" service status with all closed work orders within 30 days
        $closedStatus = ServiceStatus::where('name', 'Closed')->first();
        if ($closedStatus) {
            $closedWorkOrders = WorkOrder::query()
                ->scoped()
                ->with(['service_status', 'vendors', 'requested_by', 'managed_by', 'tasks', 'owners'])
                // Apply search filter
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                // Apply vendor filter
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                // Apply date range filter
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->where('status', 'Closed')
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(30))
                ->where('category', 'NOT LIKE', '%lawn care%')
                ->latest('completed_date')
                ->get();

            // Add the closed work orders to the Closed status
            $closedStatus->setRelation('work_orders', $closedWorkOrders);
        }

        // Add statuses at the end in specific order: Waiting on Bill → Waiting on Payment → Paid → Closed
        if ($waitingOnBillStatus) {
            $service_status->push($waitingOnBillStatus);
        }
        if ($waitingOnPaymentStatus) {
            $service_status->push($waitingOnPaymentStatus);
        }
        if ($paidStatus) {
            $service_status->push($paidStatus);
        }
        if ($closedStatus) {
            $service_status->push($closedStatus);
        }

        // Hide specific statuses from vendors
        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]);
        }

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();

        $vendors = DB::table('vendors')->select('id', 'name', 'user_id')->where('is_active', true)->orderBy('name')->get();

        $vendorUserIds = $vendors->pluck('user_id')->toArray();

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
            ->orWhere(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'vendor'))
                ->whereIn('id', $vendorUserIds)
            )
            ->orderBy('name', 'ASC')
            ->get();

        return inertia('WorkOrder/Index', [
            'title' => 'Work Orders',
            'service_status' => Inertia::defer(fn () => $service_status),
            'vendors' => Inertia::defer(fn () => $vendors),
            'categories' => Inertia::defer(fn () => $categories),
            'users' => Inertia::defer(fn () => $users),
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function show(WorkOrder $workOrder)
    {
        $workOrder->load([
            'service_status',
            'vendors.user',
            'requested_by',
            'managed_by',
            'woc.wocNumber.twilioPhoneNumber',
            'owners',
            'building',
        ])->first();

        return response()->json($workOrder, 200);
    }

    public function details(WorkOrder $workOrder)
    {
        $workOrder->load([
            'service_status',
            'vendors.user',
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
            'building',
        ]);

        $user = request()->user();

        if ($user->hasRole('vendor') && $user->vendor) {
            $workOrder->setRelation(
                'vendors',
                $workOrder->vendors
                    ->where('id', $user->vendor->id)
                    ->values()
            );
        }

        // Get all conversations for this work order
        $conversations = collect()
            ->merge($workOrder->tenant_conversation)
            ->merge($workOrder->owner_conversation)
            ->merge($workOrder->vendor_conversation)
            ->merge($workOrder->vendor_tenant_conversation)
            ->sortByDesc('created_at');

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();

        // Get service statuses for potential updates
        $serviceStatusesQuery = ServiceStatus::query();

        // Hide specific statuses from vendors
        if (request()->user()->hasRole('vendor')) {
            $serviceStatusesQuery->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Completed within 30 days',
                'Completed, Verified, Waiting on Bill',
                'Approved, Waiting on Payment',
            ]);
        }

        $serviceStatuses = $serviceStatusesQuery->get();

        // Get vendors for potential assignments
        $vendorsQuery = Vendor::with('user')->where('is_active', true);

        if ($user->hasRole('vendor') && $user->vendor) {
            $vendorsQuery->whereKey($user->vendor->id);
        }

        $vendors = $vendorsQuery->get();

        return inertia('WorkOrder/Show', [
            'title' => 'Work Order #'.$workOrder->work_order_no,
            'workOrder' => $workOrder,
            'conversations' => $conversations->values(),
            'tasks' => $workOrder->tasks,
            'invoices' => $workOrder->invoices,
            'notes' => $workOrder->notes,
            'attachments' => $workOrder->attachments,
            'vendors' => $vendors,
            'categories' => $categories,
            'serviceStatuses' => $serviceStatuses,
        ]);
    }

    public function report(WorkOrder $workOrder)
    {
        $workOrder->load([
            'service_status',
            'vendors.user',
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
        $query = ServiceStatus::with([
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
                    ->where(function ($q) {
                        $q->where('status', 'Closed')
                            ->orWhere('status', 'Canceled By Tenant');
                    })
                    ->orderBy('work_order_no', 'ASC')
                    ->limit(50);
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ]);

        // Hide specific statuses from vendors
        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
            ]);
        }

        $service_status = $query->get();

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();

        $vendors = DB::table('vendors')->select('id', 'name')->where('is_active', true)->orderBy('name')->get();

        $users = User::role(['woc', 'admin'])->get();

        return inertia('WorkOrder/Close', [
            'title' => 'Closed Work Orders',
            'service_status' => Inertia::defer(fn () => $service_status),
            'vendors' => $vendors,
            'categories' => $categories,
            'users' => $users,
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function waiting_on_payment_work_orders(Request $request)
    {
        $waitingOnPaymentStatus = ServiceStatus::with([
            'work_orders' => function ($query) {
                $query->scoped()
                    ->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                        $date = request()->only(['start_date', 'end_date']);
                        $start = Carbon::parse($date['start_date'])->startOfDay();
                        $end = Carbon::parse($date['end_date'])->endOfDay();
                        $q->whereBetween('created_date', [$start, $end]);
                    })
                    ->where('status', 'Open')
                    ->orderBy('work_order_no', 'DESC');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->where('name', 'Approved - Waiting on Payment')
            ->get();

        $vendors = DB::table('vendors')
            ->select('id', 'name')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return inertia('WorkOrder/WaitingOnPayment', [
            'title' => 'Waiting on Payment',
            'service_status' => Inertia::defer(fn () => $waitingOnPaymentStatus),
            'vendors' => $vendors,
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function paid_work_orders(Request $request)
    {
        $paidStatus = ServiceStatus::where('name', 'Paid')->first();

        if ($paidStatus) {
            $paidWorkOrders = WorkOrder::query()
                ->scoped()
                ->with(['service_status', 'vendors.user', 'requested_by', 'managed_by', 'tasks', 'owners'])
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->whereNotNull('total_cost')
                ->where('total_cost', '>', 0)
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(30))
                ->orderBy('completed_date', 'DESC')
                ->get();

            $paidStatus->setRelation('work_orders', $paidWorkOrders);
        }

        $vendors = DB::table('vendors')
            ->select('id', 'name')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return inertia('WorkOrder/Paid', [
            'title' => 'Paid Work Orders',
            'service_status' => Inertia::defer(fn () => collect([$paidStatus])),
            'vendors' => $vendors,
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function inspections_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_order',
            'work_order.owners',
            'work_orders' => function ($query) {
                $query->scoped()
                    ->when(request('search'), function ($q, $search) {
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
                    ->where('category', 'LIKE', '%move out inspection%')
                    ->where('status', 'Open');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->whereNot('name', 'Not Changed');

        $service_status = $query->get();

        // Remove statuses that should be at the end
        $waitingOnBillStatus = $service_status->firstWhere('name', 'Completed - Verified - Waiting on Bill');
        $waitingOnPaymentStatus = $service_status->firstWhere('name', 'Approved - Waiting on Payment');
        $closedStatus = $service_status->firstWhere('name', 'Closed');

        $service_status = $service_status->reject(fn ($status) => in_array($status->name, [
            'Completed - Verified - Waiting on Bill',
            'Approved - Waiting on Payment',
            'Closed',
        ]));

        // Add "Paid" service status with inspection work orders that have payment (total_cost not 0) within 30 days
        $paidStatus = ServiceStatus::where('name', 'Paid')->first();
        if ($paidStatus) {
            $paidWorkOrders = WorkOrder::query()
                ->scoped()
                ->with(['service_status', 'vendors', 'requested_by', 'managed_by', 'tasks', 'owners'])
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->where('category', 'LIKE', '%inspection%')
                ->whereNotNull('total_cost')
                ->where('total_cost', '>', 0)
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(30))
                ->latest('completed_date')
                ->get();

            $paidStatus->setRelation('work_orders', $paidWorkOrders);
        }

        // Add "Closed" service status with inspection work orders within 30 days
        $closedStatus = ServiceStatus::where('name', 'Closed')->first();
        if ($closedStatus) {
            $closedWorkOrders = WorkOrder::query()
                ->scoped()
                ->with(['service_status', 'vendors', 'requested_by', 'managed_by', 'tasks', 'owners'])
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->where('category', 'LIKE', '%inspection%')
                ->where('status', 'Closed')
                ->whereNotNull('completed_date')
                ->where('completed_date', '>=', now()->subDays(30))
                ->latest('completed_date')
                ->get();

            $closedStatus->setRelation('work_orders', $closedWorkOrders);
        }

        // Add statuses at the end in specific order
        if ($waitingOnBillStatus) {
            $service_status->push($waitingOnBillStatus);
        }
        if ($waitingOnPaymentStatus) {
            $service_status->push($waitingOnPaymentStatus);
        }
        if ($paidStatus) {
            $service_status->push($paidStatus);
        }
        if ($closedStatus) {
            $service_status->push($closedStatus);
        }

        // Hide specific statuses from vendors
        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]);
        }

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();
        $vendors = DB::table('vendors')->select('id', 'name', 'user_id')->where('is_active', true)->orderBy('name')->get();
        $vendorUserIds = $vendors->pluck('user_id')->toArray();

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
            ->orWhere(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'vendor'))
                ->whereIn('id', $vendorUserIds)
            )
            ->orderBy('name', 'ASC')
            ->get();

        return inertia('WorkOrder/Inspections', [
            'title' => 'Inspection Work Orders',
            'service_status' => Inertia::defer(fn () => $service_status),
            'vendors' => Inertia::defer(fn () => $vendors),
            'categories' => Inertia::defer(fn () => $categories),
            'users' => Inertia::defer(fn () => $users),
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function lawn_service_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_order',
            'work_order.owners',
            'work_orders' => function ($query) {
                $query->scoped()
                    ->when(request('search'), function ($q, $search) {
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
                    ->where('category', 'LIKE', '%lawn service%')
                    ->where('type', 'LIKE', '%biweekly lawn services%')
                    ->where('status', 'Open');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->whereNot('name', 'Not Changed');

        $service_status = $query->get();

        // Hide specific statuses from vendors
        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]);
        }

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();
        $vendors = DB::table('vendors')->select('id', 'name', 'user_id')->where('is_active', true)->orderBy('name')->get();
        $vendorUserIds = $vendors->pluck('user_id')->toArray();

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
            ->orWhere(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'vendor'))
                ->whereIn('id', $vendorUserIds)
            )
            ->orderBy('name', 'ASC')
            ->get();

        return inertia('WorkOrder/LawnCare', [
            'title' => 'Lawn Service Work Orders',
            'service_status' => Inertia::defer(fn () => $service_status),
            'vendors' => Inertia::defer(fn () => $vendors),
            'categories' => Inertia::defer(fn () => $categories),
            'users' => Inertia::defer(fn () => $users),
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function turnover_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_order',
            'work_order.owners',
            'work_orders' => function ($query) {
                $query->scoped()
                    ->when(request('search'), function ($q, $search) {
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
                    ->where('type', 'Turnover')
                    ->where('status', 'Open');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.requested_by',
            'work_orders.managed_by',
            'work_orders.tasks',
            'work_orders.owners',
        ])
            ->whereNot('name', 'Not Changed');

        $service_status = $query->get();

        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for follow up',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]);
        }

        $categories = DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get();
        $vendors = DB::table('vendors')->select('id', 'name', 'user_id')->where('is_active', true)->orderBy('name')->get();
        $vendorUserIds = $vendors->pluck('user_id')->toArray();

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
            ->orWhere(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'vendor'))
                ->whereIn('id', $vendorUserIds)
            )
            ->orderBy('name', 'ASC')
            ->get();

        return inertia('WorkOrder/Turnovers', [
            'title' => 'Turnover Work Orders',
            'service_status' => Inertia::defer(fn () => $service_status),
            'vendors' => Inertia::defer(fn () => $vendors),
            'categories' => Inertia::defer(fn () => $categories),
            'users' => Inertia::defer(fn () => $users),
            'filter' => $request->only(['search', 'per_page', 'vendor']),
        ]);
    }

    public function vendor_change(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'vendor_ids' => 'required|array',
            'vendor_ids.*' => 'integer|exists:vendors,id',
        ]);

        DB::beginTransaction();

        try {
            $vendorIds = collect($request->vendor_ids)->filter()->unique()->values()->all();

            $vendors = Vendor::whereIn('id', $vendorIds)->get();

            $vendorIDs = $vendors->map(
                fn (Vendor $v) => "<vendorID xsi:type=\"xsd:long\">{$v->propertyware_id}</vendorID>"
            )->all();

            // 🔥 Sync vendors (update instead of delete)
            $changes = $workOrder->vendors()->sync($vendorIds);

            /**
             * Notify only newly attached vendors
             */
            $newVendorIds = $changes['attached'] ?? [];

            if (! empty($newVendorIds)) {
                Vendor::whereIn('id', $newVendorIds)->each(function ($vendor) use ($workOrder) {
                    if ($vendor->email) {
                        try {
                            $vendor->notify(
                                new NewWorkOrderAssignNotification($workOrder)
                            );
                        } catch (\Throwable $e) {
                            Log::error('Vendor notification failed', [
                                'vendor_id' => $vendor->id,
                                'work_order_id' => $workOrder->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
            }

            // Build XML once
            $vendorIDsXml =
                '<vendorIDs xsi:type="soapenc:Array"
                    xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">'
                .implode('', $vendorIDs)
                .'</vendorIDs>';

            // Sync with PropertyWare
            $this->propertyWareServices
                ->changeWorkOrderVendors($workOrder, $vendorIDsXml);

            // Update local status
            $workOrder->update([
                'local_status' => 'Updated',
            ]);

            DB::commit();

            return back()->with('success', 'Work order vendors updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Failed to update work order vendors', [
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to update work order vendors.');
        }

    }

    public function emergency_change(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'is_emergency' => 'nullable|string',
        ]);

        $isEmergency = $request->is_emergency == 'Emergency';

        $workOrder->update(['is_emergency' => $isEmergency]);

        $serviceStatusId = $workOrder->service_status_id; // actual ID for 'New'
        $service_status = ServiceStatus::find($serviceStatusId);

        WorkOrderTask::where('work_order_id', $workOrder->id)->where('status', 'pending')->delete();

        TaskService::createTasksForWorkOrder($workOrder, $isEmergency, $serviceStatusId);

        // $propertyWare = new PropertyWareService;
        // $propertyWare->updateServiceStatus($workOrder, $service_status);

        // Broadcast the work order update
        $workOrder->load('service_status');

        return redirect()->back()->with('success', 'Work order emergency status updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function open(WorkOrder $workOrder)
    {
        $service_status = ServiceStatus::where('name', 'New')->value('id');

        // Update work order status and clear completion date first
        $workOrder->update([
            'service_status_id' => $service_status,
            'status' => 'Open',
            'local_status' => 'Created',
            'completed_date' => null,
        ]);

        // Then sync to PropertyWare
        $openWorder = $this->propertyWareServices->reOpenWorkOrder($workOrder);

        if ($openWorder) {
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

        // Update work order status and completion date first
        $workOrder->update([
            'status' => 'Closed',
            'service_status_id' => $service_status,
            'completed_date' => now()->toDateString(),
        ]);

        // Then sync to PropertyWare with the completion date
        $closeWorder = $this->propertyWareServices->closeWorkOrder($workOrder, $conversation_url);

        // if ($closeWorder) {
        //     $workOrder->tasks()->each(function ($task) {
        //         $task->delete();
        //     });

        //     // Broadcast the work order update
        //     $workOrder->load('service_status');
        // }

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

        // $wo = WorkOrder::where('work_order_no', $request->work_order_no)->first();
        // $workOrder = $propertyWare->getWorkOrder($wo->propertyware_id);

        $work_order_no = $request->work_order_no;

        $work_order_no = (int) $work_order_no;
        $workOrders = $propertyWare->getWorkOrderByNumber($work_order_no);

        $importWorkOrder = new WorkOrderService;
        $importWorkOrder->handle($workOrders);

        return redirect()->back()->with('success', 'Work orders updated successfully.');
    }

    public function export(): StreamedResponse
    {
        $export = new WorkOrdersExport;
        $spreadsheet = $this->buildWorkOrdersSpreadsheet($export);
        $filename = 'Work_Orders_Export_'.date('d-m-Y-h-i').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function buildWorkOrdersSpreadsheet(WorkOrdersExport $export): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [
            $export->headings(),
            ...$export->rows()->map(fn (array $row) => array_values($row))->all(),
        ];

        $sheet->fromArray($rows);

        foreach (range(1, count($export->headings())) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))
                ->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
