<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberJobNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Office notes on a Jobber job, from the board modal and the full job page.
 *
 * The notes are ours alone: nothing goes to Jobber, and — unlike
 * WorkOrderNotesController — there is no PropertyWare write-through, because a
 * Jobber job has no record there. Any staff member may remove any note; they
 * are office-internal, the same trust level as the job's photos and invoices.
 * A job closed on our side still takes notes on purpose: "invoiced, paid" is
 * exactly the kind of thing written after a close.
 */
class JobberJobNoteController extends Controller
{
    public function store(Request $request, Jobber $job): RedirectResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $job->jobNotes()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return redirect()->back()->with('success', 'Note added.');
    }

    public function destroy(Request $request, JobberJobNote $note): RedirectResponse
    {
        $this->authorizeStaff($request);

        $note->delete();

        return redirect()->back()->with('success', 'Note deleted.');
    }

    /**
     * Matches the rest of the Jobber pages: anyone in the office may act,
     * vendors and other outside parties may not.
     */
    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->isStaff(), 403);
    }
}
