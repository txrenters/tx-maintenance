<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attachments;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentsController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {        
        $validatedData = $request->validate([
            'title' => 'required',
            'filename' => 'required|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx|max:2048',
            'type' => 'required',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        $validatedData['user_id'] = auth()->id();
        $validatedData['created_at'] = !isset($request->date) ? now() : $request->date;

        if($request->hasFile('filename')){
            $file = $request->file('filename');
            $validatedData['filename'] = $file->store('attachments', 'public');
            $validatedData['filetype'] = $file->getMimeType();
        }

        Attachments::create($validatedData);

        return redirect()->back();
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
