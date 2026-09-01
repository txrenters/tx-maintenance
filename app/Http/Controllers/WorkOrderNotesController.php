<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\JobberTechnicianResolver;
use App\Services\PropertyWareService;
use App\Services\VendorPortalLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WorkOrderNotesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getNotes(WorkOrder $workOrder, JobberTechnicianResolver $technicians)
    {
        // Private notes are internal: owners and tenants who log in here get
        // the same view PropertyWare's own portals give them.
        $workOrder->load([
            'notes' => fn ($query) => $query->visibleTo(auth()->user()),
            'notes.user.vendor',
            'vendors',
        ]);

        // A THMP field note arrives through the shared vendor login; Jobber's
        // visit assignment names the actual technician, so the dashboard can
        // show a person instead of the company. Falls back to nothing (and the
        // UI to the vendor name) whenever the Jobber link or assignment is
        // missing. The vendor relation was only loaded for this check — it is
        // dropped again so the payload stays what the page always received.
        // getRelation: work_orders also has a "notes" text column, which the
        // plain property accessor would return instead of the loaded rows.
        $workOrder->getRelation('notes')->each(function (WorkOrderNotes $note) use ($workOrder, $technicians) {
            if ($note->user?->vendor?->isThmp()) {
                $note->setAttribute('jobber_technician', $technicians->technicianForNote($workOrder, $note->created_at));
            }

            $note->user?->unsetRelation('vendor');
        });

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
     * Save a note on the dashboard, then mirror it to PropertyWare.
     *
     * The dashboard row is the source of truth: it is written first, outside
     * any transaction, and stays whatever PropertyWare answers. The mirror is
     * best effort — a rejected push is logged and reported back as a warning
     * instead of the old unconditional "Success". Every dashboard note goes to
     * PropertyWare as Private so tenants and owners never see internal notes.
     */
    public function store(Request $request, PropertyWareService $propertyWare): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'work_order_id' => 'required|integer',
        ]);

        // Scoped lookup: a vendor can only note a work order they can see.
        $workOrder = WorkOrder::query()->findOrFail($validated['work_order_id']);

        $note = WorkOrderNotes::create([
            'work_order_id' => $workOrder->id,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'user_id' => auth()->id(),
            'is_private' => true,
        ]);

        try {
            $pushed = $propertyWare->addVendorNotes($note);
        } catch (\Throwable $exception) {
            Log::error('Work order note could not be pushed to PropertyWare.', [
                'work_order_no' => $workOrder->work_order_no,
                'note_id' => $note->id,
                'error' => $exception->getMessage(),
            ]);
            $pushed = false;
        }

        if (! $pushed) {
            return back()->with('warning', 'Note saved on the dashboard, but PropertyWare did not accept it. It stays here; IT can see the sync error in the log.');
        }

        return back();
    }

    /**
     * Remove a dashboard note.
     *
     * Vendors may only remove their own notes. A note that already lives in
     * PropertyWare is refused: there is no delete call to PropertyWare, so a
     * local delete would only make the note reappear at the next sync.
     */
    public function destroy(WorkOrderNotes $note): RedirectResponse
    {
        $user = auth()->user();

        if (! $user->hasAnyRole(['admin', 'woc', 'accounting']) && (int) $note->user_id !== (int) $user->id) {
            abort(403);
        }

        if (! blank($note->propertyware_id)) {
            return back()->with('warning', 'This note is stored in PropertyWare and would come back at the next sync. Remove it in PropertyWare instead.');
        }

        $note->delete();

        return back();
    }
}
