<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use App\Services\VendorPortalLinkService;
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

        // Copyable magic links for the Vendors tab, so a coordinator can hand a
        // vendor their link from the work order modal and the boards, not just
        // the full work order page.
        $vendorLinks = auth()->user()?->hasAnyRole(['admin', 'woc', 'accounting', 'vendor'])
            ? app(VendorPortalLinkService::class)->linksFor($workOrder)
            : collect();

        // The raw pivot token is what the link is made of — never ship it in the
        // payload itself, or one vendor's page source would carry another's key.
        $workOrder->vendors->each(function ($vendor) {
            $vendor->pivot?->makeHidden('access_token');
        });

        return response()->json(
            $workOrder->toArray() + ['vendor_links' => $vendorLinks],
            200,
        );
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
     * Remove the specified resource from storage.
     */
    public function destroy(WorkOrderNotes $note)
    {
        $note->delete();

        return redirect()->back();
    }
}
