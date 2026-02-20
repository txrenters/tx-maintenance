<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            // No vendor_id provided, check if work order has vendors
            $workOrder = WorkOrder::with('vendors')->find($validatedData['work_order_id']);
            $firstVendor = $workOrder?->vendors()->first();

            if (! $firstVendor) {
                return redirect()->back()->withErrors('No vendor assigned to this work order. Please assign a vendor first.');
            }

            $validatedData['vendor_id'] = $firstVendor->id;
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
            $validatedData['is_publish_to_owner_portal'] = $request->is_publish_to_owner_portal === 'Yes';
            $validatedData['is_publish_to_tenant_portal'] = $request->is_publish_to_tenant_portal === 'Yes';
            $validatedData['status'] = 'approved';

            $invoice = Invoice::create($validatedData);

            $propertyware = new PropertyWareService;
            $propertyware->uploadVendorInvoice($invoice->work_order_id, $invoice);

            DB::commit();

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
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        $user = User::with('vendor')->find(auth()->id());

        // Only admin, WOC, and the invoice's vendor can delete
        if (! $user->hasRole('admin') && ! $user->hasRole('woc')) {
            // If not admin/WOC, check if it's the vendor who owns this invoice
            if (! $user->vendor || $user->vendor->id !== $invoice->vendor_id) {
                abort(403, 'Unauthorized to delete this invoice.');
            }
        }

        try {
            // Delete the file from storage
            if ($invoice->filename && Storage::disk('public')->exists($invoice->filename)) {
                Storage::disk('public')->delete($invoice->filename);
            }

            $invoice->delete();

            Log::info('Invoice deleted successfully', [
                'invoice_id' => $invoice->id,
                'deleted_by' => $user->id,
            ]);

            return redirect()->back();
        } catch (\Throwable $th) {
            Log::error('Error deleting invoice:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors('Error deleting invoice');
        }
    }
}
