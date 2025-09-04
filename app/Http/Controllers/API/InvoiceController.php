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
        $validatedData = $request->validate([
            'title' => 'required',
            'filename' => 'required|mimes:jpg,jpeg,png,pdf|max:2048',
            'amount' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
            'is_publish_to_owner_portal' => 'required',
            'is_publish_to_tenant_portal' => 'required',
        ]);

        $user = User::with('vendor')->find(auth()->id());
        $validatedData['vendor_id'] = $user->vendor->id;
        // $validatedData['vendor_id'] = 6; // test only

        if ($request->hasFile('filename')) {
            $file = $request->file('filename');
            $validatedData['filename'] = $file->store('invoices', 'public');
            $validatedData['filetype'] = $file->getMimeType();
        }

        DB::beginTransaction();
        try {
            $validatedData['is_publish_to_owner_portal'] = (bool) $request->is_publish_to_owner_portal === 'Yes';
            $validatedData['is_publish_to_tenant_portal'] = (bool) $request->is_publish_to_tenant_portal === 'Yes';

            $invoice = Invoice::create($validatedData);

            $propertyware = new PropertyWareService;
            $propertyware->uploadVendorInvoice($invoice->work_order_id, $invoice);

            DB::commit();

            return redirect()->back()->with('Success uploading invoices');
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error uploading invoices:', ['error' => $th->getMessage()]);

            return redirect()->back()->withErrors('Error uploading invoices');
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
}
