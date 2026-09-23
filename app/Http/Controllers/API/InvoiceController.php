<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractInvoiceNumber;
use App\Jobs\NotifyOperationAccountingOfTurnoverInvoice;
use App\Models\Invoice;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(WorkOrder $workOrder)
    {
        $workOrder->load(['invoices.vendor']);

        return response()->json($workOrder, 200);
    }

    public function store(Request $request)
    {
        $user = User::with('vendor')->find(auth()->id());

        // Prevent tenants and owners from uploading invoices
        if ($user->hasRole('tenant') || $user->hasRole('owner')) {
            abort(403, 'Unauthorized to upload invoices.');
        }

        $validatedData = $request->validate([
            'title' => 'required',
            'invoice_number' => 'nullable|string|max:100',
            'filename' => 'required|mimes:jpg,jpeg,png,pdf',
            'amount' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
            'is_publish_to_owner_portal' => 'required',
            'is_publish_to_tenant_portal' => 'required',
            'vendor_id' => 'nullable|exists:vendors,id',
        ]);

        // Determine vendor_id based on user role
        if ($user->vendor) {
            // If user is a vendor, use their vendor_id
            $validatedData['vendor_id'] = $user->vendor->id;
        } elseif (! empty($validatedData['vendor_id'])) {
            // Admin/WOC selected a vendor from dropdown
            // Verify the selected vendor is actually assigned to this work order
            $workOrder = WorkOrder::with('vendors')->find($validatedData['work_order_id']);
            $vendorExists = $workOrder?->vendors()->where('vendors.id', $validatedData['vendor_id'])->exists();

            if (! $vendorExists) {
                return redirect()->back()->withErrors('Selected vendor is not assigned to this work order.');
            }
        } else {
            // No vendor_id provided, auto-assign first vendor if available, otherwise allow null
            $workOrder = WorkOrder::with('vendors')->find($validatedData['work_order_id']);
            $firstVendor = $workOrder?->vendors()->first();
            $validatedData['vendor_id'] = $firstVendor?->id;
        }

        if ($request->hasFile('filename')) {
            $file = $request->file('filename');
            $extension = $file->getClientOriginalExtension();
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $request->title);
            $uniqueName = $safeName.'_'.uniqid().'.'.$extension;
            $validatedData['filename'] = $file->storeAs('invoices', $uniqueName, 'public');
            $validatedData['filetype'] = $file->getMimeType();
        }

        DB::beginTransaction();
        try {
            // Only admin/WOC may publish an invoice to the owner or tenant
            // portal. Any other uploader (e.g. a vendor) is always "No".
            $canPublish = $user->hasRole('admin') || $user->hasRole('woc');
            $validatedData['is_publish_to_owner_portal'] = $canPublish && $request->is_publish_to_owner_portal === 'Yes';
            $validatedData['is_publish_to_tenant_portal'] = $canPublish && $request->is_publish_to_tenant_portal === 'Yes';
            $validatedData['status'] = 'approved';

            $invoice = Invoice::create($validatedData);

            $propertyware = new PropertyWareService;
            $propertyware->uploadVendorInvoice($invoice->work_order_id, $invoice);

            DB::commit();

            // Nobody typed the vendor's invoice number, so read it out of the
            // file. Queued first so it lands before the accounting email below.
            if (blank($invoice->invoice_number)) {
                ExtractInvoiceNumber::dispatch($invoice->id);
            }

            // Turnover invoices are billed through Operation Accounting, so
            // tell them the moment one lands.
            $invoiceWorkOrder = WorkOrder::withoutGlobalScopes()->find($invoice->work_order_id);
            if ($invoiceWorkOrder?->isTurnover()) {
                NotifyOperationAccountingOfTurnoverInvoice::dispatch($invoice->id);
            }

            return redirect()->back()->with('success', 'Success uploading invoices');
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error uploading invoices:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'Error uploading invoices']);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $invoice->update([
            'status' => $request->status,
        ]);

        return redirect()->back();
    }

    /**
     * Archive the invoice rather than delete it.
     *
     * An invoice is an accounting document that was pushed to PropertyWare and
     * is never synced back, so a delete here is permanent. Archiving hides it
     * from every normal view and deliberately leaves the uploaded file in
     * storage, so a restore still opens the original document.
     */
    public function destroy(Invoice $invoice)
    {
        $user = User::with('vendor')->find(auth()->id());

        // Only admin, WOC, and the invoice's vendor can archive
        if (! $user->hasRole('admin') && ! $user->hasRole('woc')) {
            // If not admin/WOC, check if it's the vendor who owns this invoice
            if (! $user->vendor || $user->vendor->id !== $invoice->vendor_id) {
                abort(403, 'Unauthorized to archive this invoice.');
            }
        }

        try {
            $invoice->archive($user);

            activity('invoice')
                ->causedBy($user)
                ->performedOn($invoice)
                ->withProperties([
                    'title' => $invoice->title,
                    'invoice_number' => $invoice->invoice_number,
                    'work_order_id' => $invoice->work_order_id,
                ])
                ->log('archived');

            return redirect()->back()->with('success', 'Invoice archived.');
        } catch (\Throwable $th) {
            Log::error('Error archiving invoice:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors('Error archiving invoice');
        }
    }

    /**
     * Move an invoice that was uploaded against the wrong work order.
     *
     * Office only. The invoice row keeps everything else (vendor, amount,
     * status, posted mark, file) and only changes work order. PropertyWare
     * gets a fresh copy of the document on the new work order, because the
     * original upload attached it to the old one and PropertyWare never hands
     * back a document id we could move; the old copy stays there.
     */
    public function transfer(Request $request, Invoice $invoice)
    {
        $user = User::find(auth()->id());

        abort_unless($user->hasRole('admin') || $user->hasRole('woc'), 403, 'Unauthorized to transfer this invoice.');

        $validated = $request->validate([
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
        ]);

        $fromWorkOrder = WorkOrder::withoutGlobalScopes()->find($invoice->work_order_id);
        $toWorkOrder = WorkOrder::withoutGlobalScopes()->find($validated['work_order_id']);

        if ($toWorkOrder->id === $invoice->work_order_id) {
            return redirect()->back()->withErrors(['work_order_id' => 'The invoice is already on that work order.']);
        }

        try {
            $invoice->update(['work_order_id' => $toWorkOrder->id]);

            $copiedToPropertyWare = app(PropertyWareService::class)->uploadVendorInvoice($toWorkOrder->id, $invoice->fresh());

            activity('invoice')
                ->causedBy($user)
                ->performedOn($invoice)
                ->withProperties([
                    'title' => $invoice->title,
                    'invoice_number' => $invoice->invoice_number,
                    'from_work_order_id' => $fromWorkOrder?->id,
                    'from_work_order_no' => $fromWorkOrder?->work_order_no,
                    'to_work_order_id' => $toWorkOrder->id,
                    'to_work_order_no' => $toWorkOrder->work_order_no,
                    'copied_to_propertyware' => (bool) $copiedToPropertyWare,
                ])
                ->log('transferred');

            // Same rule as an upload: an invoice landing on a turnover work
            // order is billed through Operation Accounting.
            if ($toWorkOrder->isTurnover()) {
                NotifyOperationAccountingOfTurnoverInvoice::dispatch($invoice->id);
            }

            $message = 'Invoice moved to WO #'.$toWorkOrder->work_order_no.'.';

            if (! $copiedToPropertyWare) {
                return redirect()->back()
                    ->with('success', $message)
                    ->with('warning', 'The copy could not be sent to PropertyWare; attach the file to WO #'.$toWorkOrder->work_order_no.' there by hand.');
            }

            return redirect()->back()->with('success', $message);
        } catch (\Throwable $th) {
            Log::error('Error transferring invoice:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'Error transferring invoice']);
        }
    }

    /**
     * Put an archived invoice back. Office only: a vendor may archive their
     * own upload but must not be able to reinstate one the office filed away.
     */
    public function restore(int $invoice)
    {
        $user = User::find(auth()->id());

        abort_unless($user->hasRole('admin') || $user->hasRole('woc'), 403, 'Unauthorized to restore this invoice.');

        // NotArchivedScope hides exactly the row this action exists to find,
        // so the archived invoice is looked up without it. InvoiceScope's role
        // rules are left in place.
        $invoice = Invoice::withoutGlobalScope(NotArchivedScope::class)->findOrFail($invoice);

        try {
            $invoice->unarchive();

            activity('invoice')
                ->causedBy($user)
                ->performedOn($invoice)
                ->withProperties([
                    'title' => $invoice->title,
                    'invoice_number' => $invoice->invoice_number,
                    'work_order_id' => $invoice->work_order_id,
                ])
                ->log('restored');

            return redirect()->back()->with('success', 'Invoice restored.');
        } catch (\Throwable $th) {
            Log::error('Error restoring invoice:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors('Error restoring invoice');
        }
    }
}
