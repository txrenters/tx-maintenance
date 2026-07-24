<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HoaNoticeExtractor;
use App\Services\HoaPropertyMatcher;
use App\Services\HoaViolationIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Staff entry point for HOA violation notices: upload the notice PDF for a
 * property and the intake service does the rest (create the work order in
 * PropertyWare, attach the PDF, AI-read the notice into the description, set
 * Tenant Easy Fix, and text the tenant their portal link with the
 * 5-business-day deadline).
 *
 * The board mirrors the other work-order pages (status columns of clickable
 * cards + shared filter bar + details modal); the HOA deadline/state is
 * decorated onto each card so nothing from the old table view is lost.
 */
class HoaViolationController extends Controller
{
    public function index(Request $request)
    {
        return inertia('WorkOrder/HoaViolations', [
            'title' => 'HOA Violations',
            'service_status' => Inertia::defer(fn () => $this->boardStatuses($request)),
            'vendors' => Inertia::defer(fn () => DB::table('vendors')
                ->select('id', 'name', 'user_id')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()),
            'categories' => Inertia::defer(fn () => DB::table('work_order_categories')
                ->select('name', 'id')
                ->orderBy('name')
                ->get()),
            'types' => Inertia::defer(fn () => $this->workOrderTypeOptions()),
            'users' => Inertia::defer(fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'woc'))
                ->orderBy('name')
                ->get()),
            'filter' => $request->only(['search', 'vendor', 'category']),
            'buildings' => Building::query()
                ->orderBy('name')
                ->get(['propertyware_id', 'name'])
                ->map(fn (Building $building) => [
                    'id' => $building->propertyware_id,
                    'name' => $building->name,
                ]),
        ]);
    }

    /**
     * The board columns: every service status with its HOA work orders, each
     * card decorated with the HOA deadline/state from its tenant token.
     *
     * @return Collection<int, ServiceStatus>
     */
    private function boardStatuses(Request $request)
    {
        $statuses = ServiceStatus::with([
            'work_orders' => function ($query) use ($request) {
                $query
                    ->where('category', HoaViolationIntakeService::CATEGORY)
                    ->when($request->filled('search'), fn ($q) => $q->where('work_order_no', $request->input('search')))
                    ->when($request->filled('vendor'), fn ($q) => $q->whereHas('vendors', fn ($v) => $v->where('work_order_vendors.vendor_id', $request->input('vendor'))))
                    ->latest('id');
            },
            'work_orders.service_status',
            'work_orders.vendors.user',
            'work_orders.building',
            'work_orders.requested_by',
            'work_orders.owners',
            'work_orders.tasks',
        ])
            ->whereNot('name', 'Not Changed')
            ->get();

        $this->decorateWithHoaState($statuses);

        // Only surface columns that actually hold an HOA work order.
        return $statuses->filter(fn (ServiceStatus $status) => $status->work_orders->isNotEmpty())->values();
    }

    /**
     * Attach the per-work-order HOA state (deadline, notice date, and a single
     * status label) so the card can render it — one token query for the whole
     * board rather than one per card.
     *
     * @param  Collection<int, ServiceStatus>  $statuses
     */
    private function decorateWithHoaState($statuses): void
    {
        $workOrderIds = $statuses->flatMap(fn (ServiceStatus $status) => $status->work_orders->pluck('id'))->all();

        $tokens = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereIn('work_order_id', $workOrderIds)
            ->get()
            ->keyBy('work_order_id');

        foreach ($statuses as $status) {
            foreach ($status->work_orders as $workOrder) {
                $token = $tokens->get($workOrder->id);

                $overdue = $token !== null
                    && $token->completed_at === null
                    && $token->hoa_deadline_at !== null
                    && $token->hoa_deadline_at->isPast();

                $workOrder->setAttribute('hoa', [
                    'notice_date' => $token?->hoa_notice_date?->toDateString(),
                    'deadline' => $token?->hoa_deadline_at?->toDateString(),
                    'completed' => $token?->completed_at !== null,
                    'overdue' => $overdue,
                    'confirmation_sent' => $token?->confirmation_sent_at !== null,
                    'state' => $this->hoaStateLabel($token, $overdue),
                ]);
            }
        }
    }

    private function hoaStateLabel(?TenantUploadToken $token, bool $overdue): string
    {
        return match (true) {
            $token?->confirmation_sent_at !== null => 'Confirmed',
            $token?->completed_at !== null => 'Completed',
            $overdue => 'Overdue — needs vendor',
            $token?->hoa_deadline_at !== null => 'Waiting on tenant',
            default => 'No tenant link',
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function workOrderTypeOptions()
    {
        return WorkOrder::withoutGlobalScopes()
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(fn ($type) => trim((string) $type))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Step 1 — scan the uploaded PDF and return the notices it contains, each
     * with its AI-read violation and a best-guess property match, so staff can
     * confirm or correct the property before anything is created. Read-only:
     * this creates no work orders.
     */
    public function detect(Request $request, HoaNoticeExtractor $extractor, HoaPropertyMatcher $matcher)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:51200',
        ]);

        $pdfContents = (string) file_get_contents($request->file('file')->getRealPath());
        $mime = $request->file('file')->getMimeType() ?: 'application/pdf';

        try {
            $notices = $extractor->extractNotices($pdfContents, $mime);
        } catch (\Throwable $exception) {
            Log::error('HOA notice scan failed.', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'Could not read the notice. Please try again.'], 422);
        }

        return response()->json([
            'notices' => collect($notices)->map(function (array $notice) use ($matcher) {
                $match = $matcher->match($notice['property_address']);

                return [
                    'page' => $notice['page'],
                    'property_address' => $notice['property_address'],
                    'description' => $notice['description'],
                    'violation_items' => $notice['violation_items'],
                    'hoa_name' => $notice['hoa_name'],
                    'notice_date' => $notice['notice_date']?->toDateString(),
                    'deadline_date' => $notice['deadline_date']?->toDateString(),
                    'deadline_days' => $notice['deadline_days'],
                    'deadline_display' => $this->deadlineDisplay($notice),
                    'matched' => $match['best'],
                    'candidates' => $match['candidates'],
                    'contacts' => $this->propertyContacts($match['best']['id'] ?? null),
                ];
            })->values(),
        ]);
    }

    /**
     * The tenant and owner currently on record for a property, so staff can
     * confirm the match is right before the work order (and its automated
     * tenant/owner messages) are created. Read from the property's most recent
     * work order — that carries the live lease/owner linkage from PropertyWare.
     */
    public function contacts(Request $request)
    {
        $validated = $request->validate([
            'building_id' => 'required|exists:buildings,propertyware_id',
        ]);

        return response()->json($this->propertyContacts((int) $validated['building_id']));
    }

    /**
     * @return array{tenant: ?string, owner: ?string}
     */
    private function propertyContacts(?int $buildingId): array
    {
        if ($buildingId === null) {
            return ['tenant' => null, 'owner' => null];
        }

        $workOrder = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->where('building_id', $buildingId)
            ->with(['requested_by', 'owners'])
            ->latest('id')
            ->first();

        if ($workOrder === null) {
            return ['tenant' => null, 'owner' => null];
        }

        $tenant = $workOrder->requested_by;
        $owner = $workOrder->primaryOwner();

        return [
            'tenant' => $tenant
                ? trim(trim(($tenant->first_name ?? '').' '.($tenant->last_name ?? '')).($tenant->email ? ' · '.$tenant->email : '')) ?: null
                : null,
            'owner' => $owner
                ? trim(trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')).($owner->email ? ' · '.$owner->email : '')) ?: null
                : null,
        ];
    }

    /**
     * Step 2 — create one work order per confirmed notice. The frontend resends
     * the same PDF plus the reviewed notices (each with the chosen building),
     * so no AI is re-run and the property each WO lands on is exactly what staff
     * confirmed on screen.
     */
    public function store(Request $request, HoaViolationIntakeService $intake)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:51200',
            'notices' => 'required|array|min:1',
            'notices.*.building_id' => 'required|exists:buildings,propertyware_id',
            'notices.*.pages' => 'required|array|min:1',
            'notices.*.pages.*' => 'integer|min:1',
            'notices.*.description' => 'nullable|string',
            'notices.*.violation_items' => 'nullable|array',
            'notices.*.violation_items.*' => 'string',
            'notices.*.hoa_name' => 'nullable|string',
            'notices.*.notice_date' => 'nullable|date',
            'notices.*.deadline_date' => 'nullable|date',
            'notices.*.deadline_days' => 'nullable|integer|min:1',
        ]);

        $pdfContents = (string) file_get_contents($request->file('file')->getRealPath());
        $originalName = $request->file('file')->getClientOriginalName();
        $mime = $request->file('file')->getMimeType() ?: 'application/pdf';

        $created = 0;
        $failed = 0;

        foreach ($validated['notices'] as $notice) {
            $building = Building::query()->where('propertyware_id', $notice['building_id'])->first();

            if ($building === null) {
                $failed++;

                continue;
            }

            try {
                // The full original notice is attached to every property's work
                // order (per IT lead: attach the whole document to the PW work
                // order's DOCS), so no per-page splitting.
                $intake->createFromNotice(
                    $building,
                    $pdfContents,
                    [
                        'description' => $notice['description'] ?? null,
                        'violation_items' => $notice['violation_items'] ?? [],
                        'hoa_name' => $notice['hoa_name'] ?? null,
                        'notice_date' => filled($notice['notice_date'] ?? null) ? Carbon::parse($notice['notice_date']) : null,
                        'deadline_date' => filled($notice['deadline_date'] ?? null) ? Carbon::parse($notice['deadline_date']) : null,
                        'deadline_days' => $notice['deadline_days'] ?? null,
                        'file_name' => $originalName,
                        'mime' => $mime,
                    ],
                );

                $created++;
            } catch (\Throwable $exception) {
                $failed++;
                Log::error('HOA violation intake failed for a notice.', [
                    'building_propertyware_id' => $notice['building_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($created === 0) {
            return back()->withErrors(['error' => 'Could not create any HOA work orders. Please try again.']);
        }

        $message = $created === 1
            ? '1 HOA violation work order created.'
            : "{$created} HOA violation work orders created.";

        if ($failed > 0) {
            $message .= " {$failed} could not be created — please retry those.";
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array{deadline_date: ?Carbon, deadline_days: ?int, notice_date: ?Carbon}  $notice
     */
    private function deadlineDisplay(array $notice): ?string
    {
        if ($notice['deadline_date'] instanceof Carbon) {
            return $notice['deadline_date']->format('M j, Y');
        }

        if (filled($notice['deadline_days'])) {
            $from = $notice['notice_date'] instanceof Carbon ? $notice['notice_date'] : now();

            return $from->copy()->addDays((int) $notice['deadline_days'])->format('M j, Y')
                .' ('.$notice['deadline_days'].' days)';
        }

        return null;
    }
}
