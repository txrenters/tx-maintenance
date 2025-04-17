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

class AttachmentsController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function multiple_store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'type' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
            'files' => 'required|array',
            'files.*.file' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx',
            'files.*.name' => 'required|string',
            'files.*.type' => 'required|string',
        ]);

        $user_id = auth()->id();

        $savedFiles = [];

        $propertyware = new PropertyWareService;

        foreach ($validatedData['files'] as $fileData) {
            $file = $fileData['file'];
            $originalName = $fileData['name'];
            $mimeType = $fileData['type'];
    
            $path = $file->store('attachments', 'public');

            $files = [
                'title' => $validatedData['title'],
                'filename' => $path,
                'filetype' => $mimeType,
                'type' => $validatedData['type'],
                'work_order_id' => $validatedData['work_order_id'],
                'user_id' => $user_id,
                'created_at' =>  $request->date ?? now(),
            ];

            $propertyware->uploadVendorAttachment($validatedData['work_order_id'], $files);

            $savedFiles[] = $files;
        }

        Attachments::insert($savedFiles);

        return redirect()->back()->with('success', 'Attachment uploaded successfully.');
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required',
            'filename' => 'required|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx|max:2048',
            'type' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $validatedData['user_id'] = auth()->id();
        $validatedData['created_at'] = $request->date ?? now();

        if ($request->hasFile('filename')) {
            $file = $request->file('filename');
            $validatedData['filename'] = $file->store('attachments', 'public');
            $validatedData['filetype'] = $file->getMimeType();
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
