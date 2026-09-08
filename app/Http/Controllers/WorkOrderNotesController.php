<?php

namespace App\Http\Controllers;

use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\JobberTechnicianResolver;
use App\Services\PropertyWareService;
use App\Services\VendorPortalLinkService;
use App\Services\WorkOrderNotePushService;
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
            return back()->with('warning', 'Note saved on the dashboard, but PropertyWare did not accept it. The app keeps retrying on its own; you can also use Send to PropertyWare now on the note. IT can see the reason in the log.');
        }

        return back();
    }

    /**
     * Send a dashboard note to PropertyWare again, by hand.
     *
     * The scheduled re-push (notes:push-pending) covers this on its own; the
     * link on the note is for the coordinator looking at it right now.
     * PropertyWare is read first so a note that did arrive is linked rather
     * than doubled, and nothing is sent while PropertyWare cannot be read.
     */
    public function push(WorkOrderNotes $note, WorkOrderNotePushService $pusher): RedirectResponse
    {
        if (! auth()->user()?->hasAnyRole(['admin', 'woc', 'accounting'])) {
            abort(403);
        }

        if ($note->user_id === null) {
            return back()->with('warning', 'This note came from PropertyWare; there is nothing to send.');
        }

        if (! blank($note->propertyware_id)) {
            return back()->with('warning', 'This note is already in PropertyWare.');
        }

        $workOrder = WorkOrder::withoutGlobalScope(WorkOrderScope::class)->find($note->work_order_id);

        if ($workOrder === null || blank($workOrder->propertyware_id)) {
            return back()->with('warning', 'This work order is not in PropertyWare yet, so the note cannot be sent.');
        }

        if (! $pusher->reconcile($workOrder)) {
            return back()->with('warning', 'PropertyWare could not be read just now, so the note was not sent. Try again in a few minutes; the app also retries on its own.');
        }

        if (! blank($note->fresh()->propertyware_id)) {
            return back()->with('warning', 'PropertyWare already had this note. It is linked now.');
        }

        if (! $pusher->push($note)) {
            return back()->with('warning', 'PropertyWare did not accept the note. It stays here and the app keeps retrying; IT can see the reason in the log.');
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

    /**
     * Change a note's subject and body.
     *
     * Unlike deleting, PropertyWare can take an edit (updateNote), so a note it
     * owns is editable here too rather than being read-only.
     *
     * PropertyWare goes first and the local row is saved only once it accepts.
     * This is the reverse of store(), deliberately: a failed push on a new note
     * is retried by notes:push-pending, but the importer overwrites
     * PropertyWare-owned rows from PropertyWare, so a local-first edit that
     * failed to reach it would be silently reverted at the next sync.
     */
    public function update(Request $request, WorkOrderNotes $note, PropertyWareService $propertyWare): RedirectResponse
    {
        $user = auth()->user();

        if (! $user->hasAnyRole(['admin', 'woc', 'accounting']) && (int) $note->user_id !== (int) $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        // A note with no PropertyWare id has not been taken yet; it is saved
        // here and notes:push-pending sends the corrected text on its own.
        if (! blank($note->propertyware_id)) {
            try {
                $updated = $propertyWare->updateNote($note, $validated['subject'], $validated['body']);
            } catch (\Throwable $exception) {
                Log::error('Work order note edit could not be sent to PropertyWare.', [
                    'note_id' => $note->id,
                    'error' => $exception->getMessage(),
                ]);
                $updated = false;
            }

            if (! $updated) {
                return back()->with('warning', 'PropertyWare did not accept the change, so the note was left as it was. Try again in a few minutes; IT can see the reason in the log.');
            }
        }

        $note->update([
            'subject' => $validated['subject'],
            'body' => $validated['body'],
        ]);

        return back();
    }
}
