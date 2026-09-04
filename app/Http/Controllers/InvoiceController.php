<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
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
            'name' => fn ($query, string $direction) => $query->orderBy('title', $direction),
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
                    ->selectRaw("COALESCE(NULLIF(buildings.address, ''), buildings.name)")
                    ->leftJoin('buildings', 'buildings.propertyware_id', '=', 'work_orders.building_id')
                    ->whereColumn('work_orders.id', 'invoices.work_order_id')
                    ->limit(1),
                $direction
            ),
            'amount' => fn ($query, string $direction) => $query->orderBy('amount', $direction),
            'uploaded' => fn ($query, string $direction) => $query->orderBy('created_at', $direction),
        ];
    }

    public function index(Request $request)
    {
        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? Invoice::count() : $request->per_page)
        : 10;

        $sortable = $this->sortableColumns();
        $sort = $request->input('sort');
        $sort = isset($sortable[$sort]) ? $sort : null;
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $occupancy = in_array($request->input('occupancy'), ['vacant', 'occupied'], true)
            ? $request->input('occupancy')
            : null;

        $invoices = Invoice::query()
            ->with(['work_order.building', 'vendor'])
            ->filter(request(['search']))
            ->when($occupancy, fn ($query) => $query->whereHas(
                'work_order',
                fn ($workOrder) => $occupancy === 'vacant' ? $workOrder->vacant() : $workOrder->occupied()
            ))
            ->when(
                $sort,
                fn ($query) => $sortable[$sort]($query, $direction),
                // Newest upload first: accounting reads this list against a
                // payment cutoff.
                fn ($query) => $query->latest()
            )
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($invoice) {
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
                    'vendor' => $invoice->vendor?->name,
                    'created_at' => $invoice->created_at?->toIso8601String(),
                ];
            });

        return inertia('Invoice/Index', [
            'title' => 'Invoices',
            'invoices' => $invoices,
            'filter' => $request->only(['search', 'per_page', 'occupancy']),
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }
}
