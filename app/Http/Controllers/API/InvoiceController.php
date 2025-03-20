<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(WorkOrder $workOrder)
    {
        $workOrder->load(['invoices']);
    
        return response()->json($workOrder, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'filename' => 'required|mimes:jpg,jpeg,png,pdf|max:2048',
            'amount' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $user = User::with('vendor')->find(auth()->id());
        // $validatedData['vendor_id'] = $user->vendor->id;
        $validatedData['vendor_id'] = 6; // test only

        if($request->hasFile('filename')){
            $file = $request->file('filename');
            $validatedData['filename'] = $file->store('invoices', 'public');
            $validatedData['filetype'] = $file->getMimeType();
        }

        Invoice::create($validatedData);

        return redirect()->back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $invoice)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $invoice->update([
            'status' => $request->status
        ]);

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        //
    }
}
