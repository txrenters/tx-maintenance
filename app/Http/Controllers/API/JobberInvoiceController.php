<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Jobber;
use App\Models\JobberJobInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Staff invoice uploads against a Jobber job.
 *
 * Mirrors API\InvoiceController but writes to jobber_job_invoices and never
 * calls PropertyWareService::uploadVendorInvoice(): a Jobber job has no
 * PropertyWare record to attach an invoice to.
 */
class JobberInvoiceController extends Controller
{
    public function store(Request $request, Jobber $job)
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'filename' => 'required|file|mimes:jpg,jpeg,png,pdf|max:51200',
        ]);

        // An invoice can only be filed against a vendor actually working the job.
        if (! empty($validated['vendor_id'])
            && ! $job->vendors()->where('vendors.id', $validated['vendor_id'])->exists()) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Selected vendor is not assigned to this job.',
            ]);
        }

        $file = $request->file('filename');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['title']);
        $uniqueName = $safeName.'_'.uniqid().'.'.$file->getClientOriginalExtension();

        JobberJobInvoice::create([
            'jobber_job_id' => $job->id,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'uploaded_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'filename' => $file->storeAs('jobber-invoices', $uniqueName, 'public'),
            'filetype' => $file->getMimeType(),
            'status' => 'approved',
        ]);

        return redirect()->back()->with('success', 'Invoice uploaded successfully!');
    }

    public function destroy(Request $request, JobberJobInvoice $invoice)
    {
        $this->authorizeStaff($request);

        Storage::disk('public')->delete($invoice->filename);
        $invoice->delete();

        return redirect()->back()->with('success', 'Invoice deleted successfully!');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc', 'accounting']), 403);
    }
}
