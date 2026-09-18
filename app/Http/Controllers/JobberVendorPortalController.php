<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberJobAttachment;
use App\Models\JobberJobInvoice;
use App\Models\JobberJobVendor;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * No-login portal for a vendor assigned to a Jobber job (a non-TexasRenters
 * client property). Access is gated entirely by the magic-link token.
 *
 * Everything shown is strictly scoped to the one vendor holding the token: they
 * never see the other vendors on the job, nor anyone else's invoices.
 */
class JobberVendorPortalController extends Controller
{
    public function show(Request $request)
    {
        /** @var Jobber $job */
        $job = $request->attributes->get('portal_jobber_job');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');
        /** @var JobberJobVendor $assignment */
        $assignment = $request->attributes->get('portal_assignment');

        // Office-provided "before" photos plus anything this vendor uploaded.
        $attachments = JobberJobAttachment::query()
            ->where('jobber_job_id', $job->id)
            ->where(function ($q) use ($vendor) {
                $q->where('type', 'before')
                    ->orWhere('vendor_id', $vendor->id);
            })
            ->latest()
            ->get();

        // This vendor's own invoices for this job.
        $invoices = JobberJobInvoice::query()
            ->where('jobber_job_id', $job->id)
            ->where('vendor_id', $vendor->id)
            ->latest()
            ->get();

        return inertia('JobberPortal/Show', [
            'title' => 'Job #'.$job->job_number,
            'token' => $assignment->access_token,
            'vendorName' => $vendor->name,
            // A closed job stays readable so the vendor keeps their record of
            // it, but goes read-only.
            'isClosed' => $job->isClosedLocally(),
            'job' => [
                'job_number' => $job->job_number,
                'title' => $job->title,
                'job_status' => $job->job_status,
                'instructions' => $job->instructions,
                'start_at' => $job->start_at,
                'end_at' => $job->end_at,
                'property_address' => $job->property?->full_address,
                // Only the contact facts a vendor needs to get on site. The
                // full client record is deliberately not serialized.
                'client_name' => trim(($job->client?->first_name ?? '').' '.($job->client?->last_name ?? '')) ?: null,
                'client_phone' => $job->client?->phone,
            ],
            'attachments' => $attachments->map(fn (JobberJobAttachment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'url' => asset('storage/'.$a->filename),
                'is_image' => $a->isImage(),
            ])->values(),
            'invoices' => $invoices->map(fn (JobberJobInvoice $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'amount' => $i->amount,
                'status' => $i->status,
                'url' => asset('storage/'.$i->filename),
            ])->values(),
            // Drives the disabled state and hint on the invoice form. The
            // server guard in uploadInvoice() is what actually enforces this.
            'can_upload_invoice' => $this->hasVendorPhotos($job, $vendor),
        ]);
    }

    /**
     * Whether this vendor has uploaded anything of their own to this job.
     * Photos are the office's only proof the work happened, so an invoice is
     * refused until at least one exists — see uploadInvoice(). The office's own
     * "before" photos carry another vendor_id and so do not satisfy it.
     */
    private function hasVendorPhotos(Jobber $job, Vendor $vendor): bool
    {
        return JobberJobAttachment::query()
            ->where('jobber_job_id', $job->id)
            ->where('vendor_id', $vendor->id)
            ->exists();
    }

    /**
     * Upload one or more photos against the job.
     *
     * Nothing here syncs to PropertyWare — a Jobber job has no PropertyWare
     * record, and jobber_job_attachments is invisible to that sync by design.
     */
    public function uploadAttachments(Request $request)
    {
        /** @var Jobber $job */
        $job = $request->attributes->get('portal_jobber_job');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $this->abortIfClosed($job);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'type' => 'nullable|in:before,after,attachment',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt|max:51200',
        ]);

        try {
            $type = $validated['type'] ?? 'after';
            $title = trim($validated['title'] ?? '');
            if ($title === '') {
                $title = $type === 'attachment'
                    ? 'Vendor attachment - Job #'.$job->job_number
                    : 'Vendor photo ('.$type.') - Job #'.$job->job_number;
            }

            foreach ($request->file('files') as $file) {
                JobberJobAttachment::create([
                    'jobber_job_id' => $job->id,
                    'user_id' => $vendor->user_id,
                    'vendor_id' => $vendor->id,
                    'title' => $title,
                    'filename' => $file->store('jobber-attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => $type,
                    'uploaded_via' => 'vendor_portal',
                ]);
            }

            return back()->with('success', 'Photos uploaded successfully.');
        } catch (\Throwable $e) {
            Log::error('Jobber portal attachment upload failed', [
                'jobber_job_id' => $job->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not upload photos. Please try again.']);
        }
    }

    /**
     * Upload this vendor's invoice for the job. The vendor is taken from the
     * resolved token, never from user input.
     */
    public function uploadInvoice(Request $request)
    {
        /** @var Jobber $job */
        $job = $request->attributes->get('portal_jobber_job');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $this->abortIfClosed($job);

        // No photos, no invoice: the office bills from the vendor's own record
        // of the work, so an undocumented invoice is refused rather than chased
        // down afterwards. A correctable mistake, so it returns a message the
        // vendor can act on rather than aborting like abortIfClosed().
        if (! $this->hasVendorPhotos($job, $vendor)) {
            return back()->withErrors([
                'invoice' => 'Please upload at least one photo of the work before submitting your invoice.',
            ]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'filename' => 'required|file|mimes:jpg,jpeg,png,pdf|max:51200',
        ]);

        try {
            $file = $request->file('filename');
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['title']);
            $uniqueName = $safeName.'_'.uniqid().'.'.$file->getClientOriginalExtension();

            JobberJobInvoice::create([
                'jobber_job_id' => $job->id,
                'vendor_id' => $vendor->id,
                'title' => $validated['title'],
                'amount' => $validated['amount'],
                'filename' => $file->storeAs('jobber-invoices', $uniqueName, 'public'),
                'filetype' => $file->getMimeType(),
                'status' => 'approved',
            ]);

            return back()->with('success', 'Invoice uploaded successfully.');
        } catch (\Throwable $e) {
            Log::error('Jobber portal invoice upload failed', [
                'jobber_job_id' => $job->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['invoice' => 'Could not upload your invoice. Please try again.']);
        }
    }

    /**
     * Once the office closes a job, the portal link keeps working for reading
     * but stops accepting uploads. Enforced here as well as in the UI so a
     * stale page left open cannot post into a finished job.
     */
    private function abortIfClosed(Jobber $job): void
    {
        abort_if($job->isClosedLocally(), 403, 'This job has been closed.');
    }
}
