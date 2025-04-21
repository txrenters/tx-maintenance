<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;

class AttachmentsController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'filename' => 'required',
            'type' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $validatedData['user_id'] = auth()->id();
        $validatedData['created_at'] = $request->date ?? now();
        $validatedData['is_publish_to_owner_portal'] = $request->owner_portal == 'Yes';
        $validatedData['is_publish_to_tenant_portal'] = $request->tenant_portal == 'Yes';

        if ($request->hasFile('filename')) {
            $file = $request->file('filename');

            // Store the file
            $validatedData['filename'] = $file->store('attachments', 'public');
            $validatedData['filetype'] = $file->getMimeType();

            // Get the full path to the stored file
            $filePath = storage_path('app/public/'.$validatedData['filename']);

            // Log original file size (in KB)
            Log::info('Original file size: '.round(filesize($filePath) / 1024, 2).' KB');

            // Check if image and optimize
            if (preg_match('/image/', $validatedData['filetype'])) {
                // Optimize and overwrite
                ImageOptimizer::optimize($filePath);

                // Log optimized file size
                Log::info('Optimized file size: '.round(filesize($filePath) / 1024, 2).' KB');
            }
        }

        $attachment = Attachments::create($validatedData);

        DB::beginTransaction();
        try {
            $propertyware = new PropertyWareService;
            $propertyware->uploadVendorAttachment($validatedData['work_order_id'], $attachment);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to upload vendor attachments: '.$th->getMessage(), [
                'work_order_id' => $validatedData['work_order_id'],
                'attachment_id' => $attachment->id ?? null,
            ]);

            return redirect()->back()->withErrors(['error' => 'Failed to upload vendor attachments.']);
        }

        return redirect()->back()->with('success', 'Attachment uploaded successfully.');
    }

    public function multiple_store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'type' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
            'files' => 'required|array',
            'files.*.file' => 'required',
            'files.*.name' => 'required|string',
            'files.*.type' => 'required|string',
            'tenant_portal' => 'required',
            'owner_portal' => 'required',
        ]);

        $user_id = auth()->id();

        $savedFiles = [];

        $propertyware = new PropertyWareService;

        foreach ($validatedData['files'] as $fileData) {
            $file = $fileData['file'];
            $originalName = $fileData['name'];
            $mimeType = $fileData['type'];

            // Store file
            $path = $file->store('attachments', 'public');
            $filePath = storage_path('app/public/'.$path);

            // Log original size
            Log::info("File: $originalName | Original size: ".round(filesize($filePath) / 1024, 2).' KB');

            // If it's an image, optimize and log new size
            if (preg_match('/image/', $mimeType)) {
                ImageOptimizer::optimize($filePath);

                Log::info("File: $originalName | Optimized size: ".round(filesize($filePath) / 1024, 2).' KB');
            }

            $files = [
                'title' => $validatedData['title'],
                'filename' => $path,
                'filetype' => $mimeType,
                'type' => $validatedData['type'],
                'work_order_id' => $validatedData['work_order_id'],
                'is_publish_to_owner_portal' => $validatedData['owner_portal'] === 'Yes',
                'is_publish_to_tenant_portal' => $validatedData['tenant_portal'] === 'Yes',
                'user_id' => $user_id,
                'created_at' => $request->date ?? now(),
            ];

            $propertyware->uploadVendorAttachment($validatedData['work_order_id'], $files);

            $savedFiles[] = $files;
        }

        Attachments::insert($savedFiles);

        return redirect()->back()->with('success', 'Attachment uploaded successfully.');
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
