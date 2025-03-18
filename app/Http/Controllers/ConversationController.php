<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    public function get_vendor_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['tenants','vendor_conversation','vendors']);
    
        return response()->json($workOrder, 200);
    }

    public function get_tenant_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['tenants','tenant_conversation']);
    
        return response()->json($workOrder, 200);
    }

    public function get_owner_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['owners','owner_conversation']);
    
        return response()->json($workOrder, 200);
    }

    public function vendor_conversation(Request $request)
    {                
        $validatedData = $request->validate([
            'text' => 'required|string',
            'sender_phone_number' => 'required',
            'receiver_phone_number' => 'required',
            'work_order_id' => 'required',
            'conversation_type' => 'required',
        ]);

        DB::beginTransaction();

        try {
            // Save the message to the database
            $conversation = Conversation::create([
                'message' => $validatedData['text'],
                'sender_number' => $validatedData['sender_phone_number'],
                'receiver_number' => $validatedData['receiver_phone_number'],
                'work_order_id' => $validatedData['work_order_id'],
                'conversation_type' => $validatedData['conversation_type'],
            ]);
    
            // Send the message via Twilio
            $twilio = new TwilioService();
            $twilioResult = $twilio->sendMessage(
                "+".$validatedData['receiver_phone_number'],
                $validatedData['sender_phone_number'],
                $validatedData['text']
            );
    
            // Commit the transaction if both operations succeed
            DB::commit();
    
            // Return a success response
            return redirect()->back()->with('success', 'Message sent successfully!');
        } catch (\Exception $e) {
            // Roll back the transaction in case of an error
            DB::rollBack();
    
            // Log the error
            Log::error('Failed to send message: ' . $e->getMessage());
    
            // Return an error response
            return redirect()->back()->with('error', 'Failed to send the message. Please try again.');
        }
    }
}
