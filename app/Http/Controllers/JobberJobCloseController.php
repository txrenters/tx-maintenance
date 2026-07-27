<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Close and reopen a Jobber job from our side.
 *
 * The close is recorded on our own `closed_at` / `closed_by_user_id` columns,
 * never on `job_status`: that field belongs to Jobber and is overwritten by the
 * importer and the JOB_UPDATE webhook, so a close written there would be undone
 * on the next sync. Nothing is pushed back to Jobber — payment and the job's
 * own lifecycle stay there, per the IT lead.
 */
class JobberJobCloseController extends Controller
{
    public function store(Request $request, Jobber $job): RedirectResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'close_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Closing an already-closed job would otherwise rewrite who closed it
        // and when, losing the original record.
        if ($job->isClosedLocally()) {
            return redirect()->back()->with('success', 'This job is already closed.');
        }

        $job->forceFill([
            'closed_at' => now(),
            'closed_by_user_id' => $request->user()->id,
            'close_reason' => $validated['close_reason'] ?? null,
        ])->save();

        return redirect()->back()->with('success', 'Job closed.');
    }

    public function destroy(Request $request, Jobber $job): RedirectResponse
    {
        $this->authorizeStaff($request);

        $job->forceFill([
            'closed_at' => null,
            'closed_by_user_id' => null,
            'close_reason' => null,
        ])->save();

        return redirect()->back()->with('success', 'Job reopened.');
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
