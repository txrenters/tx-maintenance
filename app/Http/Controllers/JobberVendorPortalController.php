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
        ]);
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

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:before,after,attachment',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt|max:51200',
        ]);

        try {
            foreach ($request->file('files') as $file) {
                JobberJobAttachment::create([
                    'jobber_job_id' => $job->id,
                    'user_id' => $vendor->user_id,
                    'vendor_id' => $vendor->id,
                    'title' => $validated['title'],
                    'filename' => $file->store('jobber-attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => $validated['type'] ?? 'after',
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
}
