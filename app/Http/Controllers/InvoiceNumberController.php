<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records the vendor's own invoice number on an invoice, typed or corrected by
 * the office from the Invoices list. Operation Accounting files each bill
 * under that number and uses it to catch the same invoice arriving twice, and
 * until now it lived only inside the uploaded document.
 *
 * Deliberately separate from API\InvoiceController::update(), which writes the
 * vendor-facing approve/decline status without validation on a model that
 * guards nothing.
 */
class InvoiceNumberController extends Controller
{
    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        // InvoiceScope narrows which invoices each role can read, but it does
        // not stop a vendor resolving their own invoice here, so the write
        // needs its own gate. Staff covers accounting, who work the list.
        abort_unless((bool) $request->user()?->isStaff(), 403);

        $validated = $request->validate([
            'invoice_number' => ['nullable', 'string', 'max:100'],
        ]);

        // TrimStrings and ConvertEmptyStringsToNull have already run, so a
        // blank submission clears the number rather than storing "".
        $invoice->forceFill([
            'invoice_number' => $validated['invoice_number'] ?? null,
        ])->save();

        return redirect()->back()->with('success', 'Invoice number saved.');
    }
}
