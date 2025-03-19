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
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

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
     * Show the form for editing the specified resource.
     */
    public function edit(Attachments $attachments)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Attachments $attachments)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attachments $attachments)
    {
        //
    }
}
