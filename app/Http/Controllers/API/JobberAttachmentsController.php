<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Jobber;
use App\Models\JobberJobAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Staff photo/file uploads against a Jobber job.
 *
 * Mirrors API\AttachmentsController but writes to jobber_job_attachments and
 * never dispatches UploadAttachment: a Jobber job has no PropertyWare record.
 */
class JobberAttachmentsController extends Controller
{
    public function store(Request $request, Jobber $job)
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:before,after,attachment',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt|max:51200',
        ]);

        foreach ($request->file('files') as $file) {
            JobberJobAttachment::create([
                'jobber_job_id' => $job->id,
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'filename' => $file->store('jobber-attachments', 'public'),
                'filetype' => $file->getMimeType(),
                'type' => $validated['type'] ?? 'attachment',
                'uploaded_via' => 'staff',
            ]);
        }

        return redirect()->back()->with('success', 'Photos uploaded successfully!');
    }

    public function destroy(Request $request, JobberJobAttachment $attachment)
    {
        $this->authorizeStaff($request);

        Storage::disk('public')->delete($attachment->filename);
        $attachment->delete();

        return redirect()->back()->with('success', 'Photo deleted successfully!');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->isStaff(), 403);
    }
}
