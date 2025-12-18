<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AttachmentsController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string',
                'filename' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx|max:10240',
                'type' => 'required|in:before,after,attachment',
                'work_order_id' => 'required|exists:work_orders,id',
            ]);

            $validatedData['user_id'] = auth()->id();
            $validatedData['created_at'] = $request->date ?? now();
            $validatedData['is_publish_to_owner_portal'] = $request->owner_portal == 'Yes' ? true : false;
            $validatedData['is_publish_to_tenant_portal'] = $request->tenant_portal == 'Yes' ? true : false;

            // Process the validated file
            $file = $request->file('filename');
            $validatedData['filename'] = $file->store('attachments', 'public');
            $validatedData['filetype'] = $file->getMimeType();

            $attachment = Attachments::create($validatedData);

            UploadAttachment::dispatch($attachment);

            return redirect()->back()->with('success', 'Attachment uploaded successfully. PropertyWare sync is processing in the background.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Attachment validation failed', [
                'errors' => $e->errors(),
                'user_id' => auth()->id(),
                'work_order_id' => $request->work_order_id,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error uploading work order attachment', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => auth()->id(),
                'work_order_id' => $request->work_order_id,
                'fileName' => $request->file('filename')?->getClientOriginalName(),
            ]);

            return redirect()->back()->withErrors(['error' => 'Failed to upload attachment. Please try again.']);
        }
    }

    public function multiple_store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string',
                'type' => 'required|in:before,after,attachment',
                'work_order_id' => 'required|exists:work_orders,id',
                'files' => 'required|array',
                'files.*.file' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx|max:10240',
                'files.*.name' => 'required|string',
                'files.*.type' => 'required|string',
                'tenant_portal' => 'required|in:Yes,No',
                'owner_portal' => 'required|in:Yes,No',
            ]);

            $uploadJobs = [];

            // Process all files and store them locally (fast operation)
            foreach ($validatedData['files'] as $fileData) {
                $file = $fileData['file'];
                $mimeType = $fileData['type'];

                $path = $file->store('attachments', 'public');

                $files = [
                    'title' => $validatedData['title'],
                    'filename' => $path,
                    'filetype' => $mimeType,
                    'type' => $validatedData['type'],
                    'work_order_id' => $validatedData['work_order_id'],
                    'is_publish_to_owner_portal' => $validatedData['owner_portal'] == 'Yes' ? true : false,
                    'is_publish_to_tenant_portal' => $validatedData['tenant_portal'] == 'Yes' ? true : false,
                    'user_id' => auth()->id(),
                    'created_at' => $request->date ?? now(),
                ];

                $attachment = Attachments::create($files);

                // Collect jobs to dispatch after all files are processed
                $uploadJobs[] = new UploadAttachment($attachment);
            }

            // Dispatch all PropertyWare upload jobs at once (happens in background)
            foreach ($uploadJobs as $job) {
                dispatch($job);
            }

            $fileCount = count($validatedData['files']);
            $message = $fileCount === 1
                ? 'Attachment uploaded successfully.'
                : "{$fileCount} attachments uploaded successfully. PropertyWare sync is processing in the background.";

            return redirect()->back()->with('success', $message);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Multiple attachments validation failed', [
                'errors' => $e->errors(),
                'user_id' => auth()->id(),
                'work_order_id' => $request->work_order_id,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error uploading multiple work order attachments', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => auth()->id(),
                'work_order_id' => $request->work_order_id,
            ]);

            return redirect()->back()->withErrors(['error' => 'Failed to upload attachments. Please try again.']);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(WorkOrder $workOrder)
    {
        $workOrder->load(['attachments']);

        return response()->json($workOrder, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attachments $attachment)
    {
        if ($attachment->filename) {
            Storage::disk('public')->delete($attachment->filename);
        }

        $attachment->delete();

        return redirect()->back();
    }
}
