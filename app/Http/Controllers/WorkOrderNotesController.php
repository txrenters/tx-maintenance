<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderNotesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getNotes(WorkOrder $workOrder)
    {
        $workOrder->load(['notes', 'notes.user', 'vendors']);

        return response()->json($workOrder, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'subject' => 'required',
            'body' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $validatedData['user_id'] = auth()->id();
        $propertywareServices = new PropertyWareService;

        DB::beginTransaction();
        try {
            $created = WorkOrderNotes::create($validatedData);
            $note = $propertywareServices->addVendorNotes($created);
            if ($note) {
                DB::commit();
                Log::info('Notes created successfully!');
            }

        } catch (\Exception $th) {
            // throw $th;
            DB::rollBack();
            Log::error('Notes failed: '.$th->getMessage());
        }

        return redirect()->back();
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WorkOrderNotes $note)
    {
        $note->delete();

        return redirect()->back();
    }
}
