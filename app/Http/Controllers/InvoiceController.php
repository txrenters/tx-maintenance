<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Scopes\NotArchivedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class InvoiceController extends Controller
{
    /**
     * Sortable columns, mapped to how each is ordered. Vendor and address live
     * on related tables and are ordered by correlated subquery rather than a
     * join, so the role restrictions in InvoiceScope and the search in
     * Invoice::scopeFilter keep working against unambiguous column names.
     *
     * @return array<string, callable>
     */
    private function sortableColumns(): array
    {
        return [
            'vendor' => fn ($query, string $direction) => $query->orderBy(
                DB::table('vendors')->select('name')->whereColumn('vendors.id', 'invoices.vendor_id')->limit(1),
                $direction
            ),
            'address' => fn ($query, string $direction) => $query->orderBy(
                // Mirrors WorkOrder::propertyAddress(), which falls back to the
                // building's PropertyWare name when it has no street address.
                // Buildings join work orders on propertyware_id, not id. Built
                // on the query builder so the role scopes on the WorkOrder
                // model cannot narrow what this orders by; which invoices are
                // visible is already settled by InvoiceScope.
                DB::table('work_orders')
                    // TRIM so a whitespace-only address falls back to the name
                    // the way propertyAddress() does, whatever the collation.
                    ->selectRaw("COALESCE(NULLIF(TRIM(buildings.address), ''), buildings.name)")
                    ->leftJoin('buildings', 'buildings.propertyware_id', '=', 'work_orders.building_id')
                    ->whereColumn('work_orders.id', 'invoices.work_order_id')
                    ->limit(1),
                $direction
            ),
            'amount' => fn ($query, string $direction) => $query->orderBy('amount', $direction),
            'uploaded' => fn ($query, string $direction) => $query->orderBy('created_at', $direction),
            // Ascending groups the nulls first, which is the unposted work.
            'posted' => fn ($query, string $direction) => $query->orderBy('posted_at', $direction),
        ];
    }

    /**
     * The lease status to show for each building on the page, in one query
     * regardless of how many rows are listed.
     *
     * sync:leases keeps one row per building -- the report feed carries the
     * property's current lease -- so this is a straight lookup. Buildings are
     * keyed by PropertyWare id because that is what work_orders.building_id
     * holds. A property whose address matched no lease row simply has no entry,
     * and the column shows a dash.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, string>
     */
    private function leaseStatusesFor($invoices): array
    {
        $buildingIds = $invoices
            ->map(fn ($invoice) => $invoice->work_order?->building_id)
            ->filter()
            ->unique()
            ->values();

        if ($buildingIds->isEmpty()) {
            return [];
        }

        return Lease::query()
            ->whereIn('building_id', $buildingIds)
            ->whereNotNull('status')
            ->pluck('status', 'building_id')
            ->all();
    }

    /**
     * The base query for one side of the archive line: the live invoices, or
     * only the archived ones.
     *
     * Only NotArchivedScope is lifted, never InvoiceScope, so showing the
     * archive can't widen which invoices a role is allowed to see.
     */
    private function archiveState(bool $archived): Builder
    {
        return $archived
            ? Invoice::withoutGlobalScope(NotArchivedScope::class)->archived()
            : Invoice::query();
    }

    public function index(Request $request)
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        // Archived invoices are hidden by default and only the office may look
        // at them. A vendor asking for them is ignored rather than refused: the
        // page still works, it just shows the live list.
        $canSeeArchived = (bool) $request->user()?->hasAnyRole(['admin', 'woc']);
        $showArchived = $canSeeArchived && $request->boolean('archived');

        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? $this->archiveState($showArchived)->count() : $request->per_page)
        : 10;

        $sortable = $this->sortableColumns();
        $sort = $request->input('sort');
        $sort = isset($sortable[$sort]) ? $sort : null;
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $occupancy = in_array($request->input('occupancy'), ['vacant', 'occupied'], true)
            ? $request->input('occupancy')
            : null;

        // Posting is our own bookkeeping, so only the office may record it.
        $canPost = (bool) $request->user()?->isStaff();

        $invoices = $this->archiveState($showArchived)
            ->with(['work_order.building', 'vendor', 'postedBy', 'archivedBy'])
            ->filter($request->only(['search', 'start_date', 'end_date']))
            ->when($occupancy, fn ($query) => $query->whereHas(
                'work_order',
                fn ($workOrder) => $occupancy === 'vacant' ? $workOrder->vacant() : $workOrder->occupied()
            ))
            ->when(
                $sort,
                // The id breaks ties, so paging through a sorted list cannot
                // show the same invoice twice or skip one.
                fn ($query) => $sortable[$sort]($query, $direction)->orderBy('invoices.id', 'desc'),
                // Newest upload first: accounting reads this list against a
                // payment cutoff. The id breaks ties so invoices uploaded in
                // the same second keep a stable order across pages.
                fn ($query) => $query->latest()->latest('id')
            )
            ->paginate($perPage)
            ->withQueryString();

        $leaseStatuses = $this->leaseStatusesFor($invoices->getCollection());

        $invoices->through(function ($invoice) use ($leaseStatuses, $canPost) {
            return [
                'id' => $invoice->id,
                'title' => $invoice->title,
                'file' => asset('storage/'.$invoice->filename),
                'filename' => $invoice->filename,
                'filetype' => $invoice->filetype,
                'amount' => Number::currency($invoice->amount),
                'status' => $invoice->status,
                'work_order_id' => $invoice->work_order_id,
                'work_order_no' => $invoice->work_order?->work_order_no,
                'address' => $invoice->work_order?->propertyAddress(),
                'lease_status' => $leaseStatuses[$invoice->work_order?->building_id] ?? null,
                'vendor' => $invoice->vendor?->name,
                'created_at' => $invoice->created_at?->toIso8601String(),
                'posted_at' => $invoice->posted_at?->toIso8601String(),
                // The list now prints the poster's name beside the time, so
                // the name only goes to the office: a vendor learns that their
                // invoice was put through, not which staff member did it.
                'posted_by' => $canPost ? $invoice->postedBy?->name : null,
                'archived_at' => $invoice->archived_at?->toIso8601String(),
                'archived_by' => $invoice->archivedBy?->name,
            ];
        });

        return inertia('Invoice/Index', [
            'title' => 'Invoices',
            'invoices' => $invoices,
            'filter' => $request->only(['search', 'per_page', 'occupancy', 'start_date', 'end_date'])
                + ['archived' => $showArchived],
            'sort' => $sort,
            'direction' => $direction,
            'canPost' => $canPost,
            'canSeeArchived' => $canSeeArchived,
            'showArchived' => $showArchived,
        ]);
    }
}
