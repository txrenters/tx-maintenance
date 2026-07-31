<?php

namespace App\Http\Controllers;

use App\Events\WorkOrderUpdated;
use App\Exports\WorkOrdersExport;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Jobs\AdoptCategorizedHoaViolationJob;
use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Jobs\UpdateWorkOrder;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderTask;
use App\Models\WorkOrderVendor;
use App\Services\EmergencyAlertService;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use App\Services\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderController extends Controller
{
    /**
     * Service statuses vendors must never see. Matches the seeder spelling
     * exactly (notably "Followup", not "Follow Up").
     *
     * @var array<int, string>
     */
    public const VENDOR_HIDDEN_STATUSES = [
        'Service Completed - Call Tenant for Followup',
        'Completed - Verified - Updating Owner',
        'Owner Completing Work',
        'Closed',
        'Paid',
    ];

    /**
     * The only work_orders columns the kanban board cards render or filter on.
     * The details modal fetches the full record separately (work_orders.data),
     * so heavy columns (client_data, description, notes, remarks, …) must stay
     * out of the board payload — serializing them for every open work order
     * exhausted PHP's memory limit in production (2026-07-22).
     *
     * @var array<int, string>
     */
    public const BOARD_CARD_COLUMNS = [
        'id',
        'work_order_no',
        'created_date',
        'completed_date',
        'scheduled_end_date',
        'location',
        'category',
        'type',
        'status',
        'local_status',
        'priority',
        'is_approved',
        'is_emergency',
        'is_repeat_issue',
        'repeat_count',
        'total_cost',
        'zone',
        'service_status_id',
        'building_id',
        'tenant_id',
    ];

    protected $propertyWareServices;

    public function __construct(PropertyWareService $propertyWareServices)
    {
        $this->propertyWareServices = $propertyWareServices;
    }

    /**
     * Relation loads trimmed to the fields the board cards actually use.
     * belongsToMany selects keep the pivot columns Eloquent appends itself.
     *
     * @return array<string, mixed>
     */
    private function boardCardRelations(string $prefix = ''): array
    {
        return [
            $prefix.'service_status:id,name',
            $prefix.'building:id,propertyware_id,name',
            $prefix.'requested_by:id,first_name,last_name',
            $prefix.'tasks:id,work_order_id,status,due_date',
            $prefix.'vendors' => fn ($q) => $q->select('vendors.id', 'vendors.name'),
            $prefix.'owners' => fn ($q) => $q->select('owners.id', 'owners.first_name', 'owners.last_name'),
        ];
    }

    /**
     * How long the board reference lists (categories, vendors, users, types)
     * stay cached. They change rarely; a new vendor/category may take up to
     * this long to appear in the board dropdowns.
     */
    private const REFERENCE_CACHE_SECONDS = 300;

    /*
     * The cache helpers below store plain arrays, never Collections or models:
     * config/cache.php sets 'serializable_classes' => false, so the file cache
     * refuses to unserialize ANY object and would hand back
     * __PHP_Incomplete_Class instead.
     */

    private function cachedCategories(): Collection
    {
        return collect(Cache::remember('board.categories', self::REFERENCE_CACHE_SECONDS, fn () => DB::table('work_order_categories')->select('name', 'id')->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()));
    }

    /** Active vendors with the columns every board variant needs. */
    private function cachedActiveVendors(): Collection
    {
        return collect(Cache::remember('board.vendors', self::REFERENCE_CACHE_SECONDS, fn () => DB::table('vendors')->select('id', 'name', 'user_id')->where('is_active', true)->orderBy('name')->get()->map(fn ($row) => (array) $row)->all()));
    }

    /** WOC staff plus the users behind active vendors, for the assignee dropdowns. */
    private function cachedBoardUsers(): Collection
    {
        return collect(Cache::remember('board.users', self::REFERENCE_CACHE_SECONDS, function () {
            $vendorUserIds = $this->cachedActiveVendors()->pluck('user_id')->all();

            return User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
                ->orWhere(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'vendor'))
                    ->whereIn('id', $vendorUserIds)
                )
                ->orderBy('name', 'ASC')
                ->get()
                ->toArray();
        }));
    }

    /**
     * A vendor's own work orders as a single flat list for the vendor "Work
     * Orders" page. The list is restricted to work orders the vendor is actually
     * tagged on (the work_order_vendors pivot) — NOT the broader WorkOrderScope
     * rule, which would also surface work orders the vendor merely has a task or
     * attachment on. Statuses vendors may never see are stripped out here. The
     * page filters by status client-side, so every visible status is also
     * returned for the dropdown.
     */
    public function vendorWorkOrders(Request $request)
    {
        $vendor = $request->user()->vendor;

        // `tasks` is scoped to the vendor's own assignments by TaskScope, so the
        // card can colour itself from the tasks that actually belong to them.
        $workOrders = $vendor
            ? $vendor->workOrders()
                ->with(['service_status', 'building', 'tasks'])
                ->orderByDesc('created_date')
                ->get()
                ->reject(fn (WorkOrder $workOrder) => $workOrder->status === 'Closed'
                    || in_array(
                        $workOrder->service_status?->name,
                        self::VENDOR_HIDDEN_STATUSES,
                        true,
                    ))
                ->values()
            : collect();

        // Build the filter dropdowns from the values that actually appear in the
        // vendor's own work orders — so options they have none of (e.g. an "HOA
        // Violation" category they never handle) never show up.
        $statuses = $workOrders
            ->map(fn (WorkOrder $workOrder) => $workOrder->service_status)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($status) => ['id' => $status->id, 'name' => $status->name]);

        $categories = $workOrders
            ->pluck('category')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return inertia('WorkOrder/VendorWorkOrders', [
            'title' => 'Work Orders',
            'workOrders' => Inertia::defer(fn () => $workOrders),
            'statuses' => $statuses,
            'categories' => $categories,
            'filter' => $request->only(['status', 'category', 'search']),
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Every heavy prop is built inside its deferred closure so the work only
        // runs on the request that actually returns it: the initial page load
        // ships the shell instantly, the deferred fetch builds the board once,
        // and partial reloads (only: ['service_status']) skip the reference
        // lists entirely. Building these inline here would run the full board
        // twice per visit — once discarded on the initial response, once for
        // the deferred fetch.
        return inertia('WorkOrder/Index', [
            'title' => 'Work Orders',
            'service_status' => Inertia::defer(fn () => $this->mainBoard($request)),
            'vendors' => Inertia::defer(fn () => $this->cachedActiveVendors()),
            'categories' => Inertia::defer(fn () => $this->cachedCategories()),
            'types' => Inertia::defer(fn () => $this->workOrderTypeOptions()),
            'users' => Inertia::defer(fn () => $this->cachedBoardUsers()),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category', 'emergency']),
        ]);
    }

    /**
     * The main kanban board: every open work order grouped by service status,
     * plus the trailing Paid and Closed buckets, honoring the request filters.
     */
    private function mainBoard(Request $request): Collection
    {
        $query = ServiceStatus::with([
            'work_orders' => function ($q) {
                $q->select(self::BOARD_CARD_COLUMNS)
                    ->scoped()
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
                    // Apply category filter
                    ->when(request('category'), function ($query, $category) {
                        $query->where('category', $category);
                    })
                    // Apply date range filter
                    ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                        $date = request()->only(['start_date', 'end_date']);
                        $start = Carbon::parse($date['start_date'])->startOfDay();
                        $end = Carbon::parse($date['end_date'])->endOfDay();
                        $query->whereBetween('created_date', [$start, $end]);
                    })
                    ->emergencyFilter()
                    ->where('status', 'Open')
                    ->where('category', 'NOT LIKE', '%move out inspection%')
                    ->where('type', 'NOT LIKE', '%Biweekly Lawn Services%')
                    ->where('type', 'NOT LIKE', '%Turnover%');
            },
            ...$this->boardCardRelations('work_orders.'),
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
                ->select(self::BOARD_CARD_COLUMNS)
                ->scoped()
                ->with($this->boardCardRelations())
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
                // Apply category filter
                ->when(request('category'), function ($query, $category) {
                    $query->where('category', $category);
                })
                // Apply date range filter
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->emergencyFilter()
                // Work orders with non-zero total_cost completed within 30 days
                ->whereNotNull('total_cost')
                ->where('total_cost', '>', 0)
                ->whereNotNull('completed_date')
                // While searching, surface matching paid work orders regardless of age.
                ->when(! request('search'), fn ($q) => $q->where('completed_date', '>=', now()->subDays(30)))
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
                ->select(self::BOARD_CARD_COLUMNS)
                ->scoped()
                ->with($this->boardCardRelations())
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
                // Apply category filter
                ->when(request('category'), function ($query, $category) {
                    $query->where('category', $category);
                })
                // Apply date range filter
                ->when(request()->filled(['start_date', 'end_date']), function ($query) {
                    $date = request()->only(['start_date', 'end_date']);
                    $start = Carbon::parse($date['start_date'])->startOfDay();
                    $end = Carbon::parse($date['end_date'])->endOfDay();
                    $query->whereBetween('created_date', [$start, $end]);
                })
                ->emergencyFilter()
                ->where('status', 'Closed')
                ->whereNotNull('completed_date')
                // While searching, surface matching closed work orders regardless of age.
                ->when(! request('search'), fn ($q) => $q->where('completed_date', '>=', now()->subDays(30)))
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

        // Hide specific statuses from vendors. This must reject from the already
        // materialized $service_status collection (including the Paid/Closed buckets
        // pushed above) — filtering the $query builder here is a no-op because it was
        // executed with ->get() earlier.
        if ($request->user()->hasRole('vendor')) {
            $service_status = $service_status->reject(fn ($status) => in_array($status->name, [
                'Service Completed - Call Tenant for Followup',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]))->values();
        }

        return $service_status;
    }

    public function show(WorkOrder $workOrder)
    {
        return redirect()->route('work_orders.details', $workOrder);
    }

    public function data(WorkOrder $workOrder)
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

    /**
     * Supporting lists (categories, vendors, users, service statuses) the reusable
     * work order modal needs to render from anywhere in the app (e.g. the global
     * notification panel), independent of any page's Inertia props.
     */
    public function modalMeta(Request $request)
    {
        $service_status = ServiceStatus::whereNot('name', 'Not Changed')->orderBy('name')->get();

        return response()->json([
            'categories' => $this->cachedCategories(),
            'types' => $this->workOrderTypeOptions(),
            'vendors' => $this->cachedActiveVendors(),
            'users' => $this->cachedBoardUsers(),
            'service_status' => $service_status,
        ]);
    }

    /**
     * Distinct, trimmed work-order "type" values already in use. Type has no
     * PropertyWare picklist table of its own, so the editable dropdown offers
     * these. Blanks are dropped so the dropdown never renders empty options.
     * Cached because it runs a DISTINCT over the whole work_orders table.
     */
    private function workOrderTypeOptions(): Collection
    {
        return collect(Cache::remember('board.types', self::REFERENCE_CACHE_SECONDS, fn () => WorkOrder::withoutGlobalScopes()
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(fn ($type) => trim((string) $type))
            ->filter()
            ->unique()
            ->values()
            ->all()));
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

        $types = $this->workOrderTypeOptions();

        // Get service statuses for potential updates
        $serviceStatusesQuery = ServiceStatus::query();

        // Hide specific statuses from vendors
        if (request()->user()->hasRole('vendor')) {
            $serviceStatusesQuery->whereNotIn('name', [
                'Service Completed - Call Tenant for Followup',
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

        // Per-vendor magic-link portal URLs (one unique link per assignment) so the
        // coordinator can copy a link for vendors who have no email on file. These
        // tokens are never exposed to owners/tenants.
        $vendorLinks = $user->hasAnyRole(['admin', 'woc', 'accounting', 'vendor'])
            ? $workOrder->vendors->map(fn ($vendor) => [
                'vendor_id' => $vendor->id,
                'name' => $vendor->name,
                'has_email' => (bool) $vendor->email,
                'url' => $vendor->pivot->access_token
                    ? route('vendor.portal.show', $vendor->pivot->access_token)
                    : null,
                'dashboard_url' => route('vendor.portal.dashboard', $vendor->ensurePortalToken()),
            ])->values()
            : collect();

        return inertia('WorkOrder/Show', [
            'title' => 'Work Order #'.$workOrder->work_order_no,
            'workOrder' => $workOrder,
            'conversations' => $conversations->values(),
            'tasks' => $workOrder->tasks,
            'invoices' => $workOrder->invoices,
            'notes' => $workOrder->notes,
            'attachments' => $workOrder->attachments,
            'vendors' => $vendors,
            'vendorLinks' => $vendorLinks,
            'categories' => $categories,
            'types' => $types,
            'serviceStatuses' => $serviceStatuses,
            // Staff-only "Open in Jobber" link (THMP jobs). Never shown to vendors.
            'canViewJobberLink' => $user->hasAnyRole(['admin', 'woc', 'accounting']),
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

        // Store the exact known spelling of the category (PropertyWare's
        // picklist values can carry invisible whitespace, e.g. "HVAC "), so
        // the sync below succeeds and board filters never split one category
        // into two.
        if (filled($validatedData['category'] ?? null)) {
            $validatedData['category'] = WorkOrderCategory::canonicalName($validatedData['category']);
        }

        try {
            $workOrder->update($validatedData);

            // Run the PropertyWare sync inline so we can tell the user whether it
            // actually synced. PropertyWare refuses edits to closed work orders, so a
            // successful local save does not guarantee the change reached PropertyWare.
            $result = (new UpdateWorkOrder($workOrder->id, $validatedData))->handle(app(PropertyWareService::class));

            $workOrder->load('service_status');

            // Re-categorizing a work order as an HOA violation puts it on the
            // HOA board, so start the same workflow the notice upload starts.
            // The job is a no-op when it is any other category or the work
            // order is already tracked.
            // Trimmed: canonicalName() returns PropertyWare's own spelling, and
            // its picklist values can carry trailing whitespace (e.g. "HVAC ").
            if (trim((string) ($validatedData['category'] ?? '')) === WorkOrder::HOA_VIOLATION_CATEGORY) {
                AdoptCategorizedHoaViolationJob::dispatch($workOrder->id);
            }

            if (! ($result['ok'] ?? false)) {
                Log::warning('Work order saved locally but not synced to PropertyWare', [
                    'work_order_no' => $workOrder->work_order_no,
                    'message' => $result['message'] ?? null,
                ]);

                return redirect()->back()->with('error', 'Saved locally, but PropertyWare did not accept the update: '.($result['message'] ?? 'Unknown error.'));
            }

            Log::info('Work Order Update Synced', ['work_order_no' => $workOrder->work_order_no]);

            return redirect()->back()->with('success', 'Work order updated and synced to PropertyWare.');

        } catch (\Throwable $th) {
            Log::error('Work Order update failed: '.$th->getMessage(), [
                'work_order_id' => $workOrder->id,
                'exception' => $th->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to update work order.');
        }
    }

    public function closed_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_orders' => function ($query) {
                $query->select(self::BOARD_CARD_COLUMNS)
                    ->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request('category'), function ($q, $category) {
                        $q->where('category', $category);
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
            ...$this->boardCardRelations('work_orders.'),
        ]);

        // Query execution happens inside the deferred closure (the builder above
        // is cheap to construct); reference lists are closures so partial
        // reloads of service_status skip them.
        return inertia('WorkOrder/Close', [
            'title' => 'Closed Work Orders',
            'service_status' => Inertia::defer(function () use ($query, $request) {
                // Hide specific statuses from vendors
                if ($request->user()->hasRole('vendor')) {
                    $query->whereNotIn('name', [
                        'Service Completed - Call Tenant for Followup',
                        'Completed - Verified - Updating Owner',
                        'Owner Completing Work',
                        'Closed',
                    ]);
                }

                return $query->get();
            }),
            'vendors' => fn () => $this->cachedActiveVendors(),
            'categories' => fn () => $this->cachedCategories(),
            'types' => fn () => $this->workOrderTypeOptions(),
            'users' => fn () => Cache::remember('board.staff_users', self::REFERENCE_CACHE_SECONDS, fn () => User::role(['woc', 'admin'])->get()->toArray()),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
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
                    ->when(request('category'), function ($q, $category) {
                        $q->where('category', $category);
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
            ...$this->boardCardRelations('work_orders.'),
        ])
            ->where('name', 'Approved - Waiting on Payment');

        // Query execution is deferred; reference lists are closures so partial
        // reloads of service_status skip them.
        return inertia('WorkOrder/WaitingOnPayment', [
            'title' => 'Waiting on Payment',
            'service_status' => Inertia::defer(fn () => $waitingOnPaymentStatus->get()),
            'vendors' => fn () => $this->cachedActiveVendors(),
            'categories' => fn () => $this->cachedCategories(),
            'types' => fn () => $this->workOrderTypeOptions(),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
        ]);
    }

    public function paid_work_orders(Request $request)
    {
        // The whole Paid bucket is built inside the deferred closure; reference
        // lists are closures so partial reloads of service_status skip them.
        return inertia('WorkOrder/Paid', [
            'title' => 'Paid Work Orders',
            'service_status' => Inertia::defer(function () {
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
                        ->when(request('category'), function ($query, $category) {
                            $query->where('category', $category);
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

                return collect([$paidStatus]);
            }),
            'vendors' => fn () => $this->cachedActiveVendors(),
            'categories' => fn () => $this->cachedCategories(),
            'types' => fn () => $this->workOrderTypeOptions(),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
        ]);
    }

    public function inspections_work_orders(Request $request)
    {
        // Same deferred structure as index(): heavy props only run on the
        // request that returns them.
        return inertia('WorkOrder/Inspections', [
            'title' => 'Inspection Work Orders',
            'service_status' => Inertia::defer(fn () => $this->inspectionsBoard($request)),
            'vendors' => Inertia::defer(fn () => $this->cachedActiveVendors()),
            'categories' => Inertia::defer(fn () => $this->cachedCategories()),
            'types' => Inertia::defer(fn () => $this->workOrderTypeOptions()),
            'users' => Inertia::defer(fn () => $this->cachedBoardUsers()),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
        ]);
    }

    /**
     * The inspections kanban board: same shape as mainBoard() but restricted
     * to move-out-inspection work orders.
     */
    private function inspectionsBoard(Request $request): Collection
    {
        $query = ServiceStatus::with([
            'work_orders' => function ($query) {
                $query->select(self::BOARD_CARD_COLUMNS)
                    ->scoped()
                    ->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request('category'), function ($q, $category) {
                        $q->where('category', $category);
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
            ...$this->boardCardRelations('work_orders.'),
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
                ->select(self::BOARD_CARD_COLUMNS)
                ->scoped()
                ->with($this->boardCardRelations())
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                ->when(request('category'), function ($query, $category) {
                    $query->where('category', $category);
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
                ->select(self::BOARD_CARD_COLUMNS)
                ->scoped()
                ->with($this->boardCardRelations())
                ->when(request('search'), function ($query, $search) {
                    $query->where('work_order_no', $search);
                })
                ->when(request('vendor'), function ($query, $vendorId) {
                    $query->whereHas('vendors', function ($q) use ($vendorId) {
                        $q->where('work_order_vendors.vendor_id', $vendorId);
                    });
                })
                ->when(request('category'), function ($query, $category) {
                    $query->where('category', $category);
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

        // Hide specific statuses from vendors. This must reject from the already
        // materialized $service_status collection (including the Paid/Closed buckets
        // pushed above) — filtering the $query builder here is a no-op because it was
        // executed with ->get() earlier.
        if ($request->user()->hasRole('vendor')) {
            $service_status = $service_status->reject(fn ($status) => in_array($status->name, [
                'Service Completed - Call Tenant for Followup',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]))->values();
        }

        return $service_status;
    }

    public function lawn_service_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_orders' => function ($query) {
                $query->select(self::BOARD_CARD_COLUMNS)
                    ->scoped()
                    ->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request('category'), function ($q, $category) {
                        $q->where('category', $category);
                    })
                    ->when(request()->filled(['start_date', 'end_date']), function ($q) {
                        $date = request()->only(['start_date', 'end_date']);
                        $start_date = Carbon::parse($date['start_date'])->startOfDay();
                        $end_date = Carbon::parse($date['end_date'])->endOfDay();

                        $q->whereBetween('created_date', [$start_date, $end_date]);
                    })
                    ->where(function ($q) {
                        $q->where('category', 'LIKE', '%lawn service%')
                            ->orWhere('type', 'LIKE', '%biweekly lawn services%');
                    })
                    ->where('status', 'Open');
            },
            ...$this->boardCardRelations('work_orders.'),
        ])
            ->whereNot('name', 'Not Changed');

        // Query execution happens inside the deferred closure; reference lists
        // come from the shared 5-minute cache.
        return inertia('WorkOrder/LawnCare', [
            'title' => 'Lawn Service Work Orders',
            'service_status' => Inertia::defer(function () use ($query, $request) {
                $service_status = $query->get();

                // Hide specific statuses from vendors. This must reject from the
                // already materialized collection — filtering the $query builder
                // after get() would be a no-op.
                if ($request->user()->hasRole('vendor')) {
                    $service_status = $service_status->reject(fn ($status) => in_array($status->name, [
                        'Service Completed - Call Tenant for Followup',
                        'Completed - Verified - Updating Owner',
                        'Owner Completing Work',
                        'Closed',
                        'Paid',
                    ]))->values();
                }

                return $service_status;
            }),
            'vendors' => Inertia::defer(fn () => $this->cachedActiveVendors()),
            'categories' => Inertia::defer(fn () => $this->cachedCategories()),
            'types' => Inertia::defer(fn () => $this->workOrderTypeOptions()),
            'users' => Inertia::defer(fn () => $this->cachedBoardUsers()),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
        ]);
    }

    public function turnover_work_orders(Request $request)
    {
        $query = ServiceStatus::with([
            'work_orders' => function ($query) {
                $query->select(self::BOARD_CARD_COLUMNS)
                    ->scoped()
                    ->when(request('search'), function ($q, $search) {
                        $q->where('work_order_no', $search);
                    })
                    ->when(request('vendor'), function ($q, $vendorId) {
                        $q->whereHas('vendors', function ($q) use ($vendorId) {
                            $q->where('work_order_vendors.vendor_id', $vendorId);
                        });
                    })
                    ->when(request('category'), function ($q, $category) {
                        $q->where('category', $category);
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
            ...$this->boardCardRelations('work_orders.'),
        ])
            ->whereNot('name', 'Not Changed');

        // Hide specific statuses from vendors. This previously ran AFTER the
        // query had executed (a no-op), letting vendors see statuses hidden on
        // every other board; applying it before get() matches the intent and
        // the other boards' behavior.
        if ($request->user()->hasRole('vendor')) {
            $query->whereNotIn('name', [
                'Service Completed - Call Tenant for Followup',
                'Completed - Verified - Updating Owner',
                'Owner Completing Work',
                'Closed',
                'Paid',
            ]);
        }

        // Query execution happens inside the deferred closure; reference lists
        // come from the shared 5-minute cache.
        return inertia('WorkOrder/Turnovers', [
            'title' => 'Turnover Work Orders',
            'service_status' => Inertia::defer(fn () => $query->get()),
            'vendors' => Inertia::defer(fn () => $this->cachedActiveVendors()),
            'categories' => Inertia::defer(fn () => $this->cachedCategories()),
            'types' => Inertia::defer(fn () => $this->workOrderTypeOptions()),
            'users' => Inertia::defer(fn () => $this->cachedBoardUsers()),
            'filter' => $request->only(['search', 'per_page', 'vendor', 'category']),
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
                    // Every assignment gets its own magic-link token so the coordinator
                    // can copy a link even when the vendor has no email on file.
                    $token = WorkOrderVendor::generateUniqueAccessToken();
                    $workOrder->vendors()->updateExistingPivot($vendor->id, [
                        'access_token' => $token,
                    ]);

                    // Generate the Work Order Information PDF, email it to the
                    // vendor, and upload it to PropertyWare. Queued so the assign
                    // request stays fast; the token above is read by the job.
                    SendVendorWorkOrderInformation::dispatch($workOrder->id, $vendor->id);
                    SendOwnerVendorAssignmentEmail::dispatch($workOrder->id, $vendor->id);
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
        $wasEmergency = (bool) $workOrder->is_emergency;

        $workOrder->update(['is_emergency' => $isEmergency]);

        $serviceStatusId = $workOrder->service_status_id; // actual ID for 'New'
        $service_status = ServiceStatus::find($serviceStatusId);

        WorkOrderTask::where('work_order_id', $workOrder->id)->where('status', 'pending')->delete();

        TaskService::createTasksForWorkOrder($workOrder, $isEmergency, $serviceStatusId);

        if ($isEmergency && ! $wasEmergency) {
            app(EmergencyAlertService::class)->workOrderMarkedEmergency($workOrder, 'staff');
        }

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
