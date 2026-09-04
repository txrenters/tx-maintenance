<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Marks an invoice as posted to the accounting system, so coordinators working
 * down the list can see which ones they have already put through.
 *
 * Deliberately separate from API\InvoiceController::update(), which writes the
 * vendor-facing approve/decline status without validation on a model that
 * guards nothing.
 */
class InvoicePostedController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeStaff($request);

        // Re-posting would rewrite who posted it and when, losing the original
        // record of who put it through.
        if ($invoice->isPosted()) {
            return redirect()->back();
        }

        $invoice->forceFill([
            'posted_at' => now(),
            'posted_by_user_id' => $request->user()->id,
        ])->save();

        return redirect()->back()->with('success', 'Invoice marked as posted.');
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeStaff($request);

        $invoice->forceFill([
            'posted_at' => null,
            'posted_by_user_id' => null,
        ])->save();

        return redirect()->back()->with('success', 'Invoice marked as not posted.');
    }

    /**
     * InvoiceScope narrows which invoices each role can read, but it does not
     * stop a vendor resolving their own invoice here, so the write needs its
     * own gate. Staff covers accounting, who work the payment cutoff.
     */
    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->isStaff(), 403);
    }
}
