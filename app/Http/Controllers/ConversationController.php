<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConversationController extends Controller
{
    public function show(WorkOrder $workOrder)
    {
        $convo = $workOrder->load(['vendor_tenant_conversation.media', 'tenant_conversation.media', 'owner_conversation.media', 'vendor_conversation.media', 'vendors']);

        return inertia('Conversation/Index', [
            'title' => 'Work Order Conversation',
            'conversations' => $convo,
        ]);
    }

    public function get_vendor_tenant_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['tenants', 'vendor_tenant_conversation.media', 'vendors']);

        return response()->json($workOrder, 200);
    }

    public function get_vendor_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['vendor_conversation.media', 'vendors']);

        return response()->json($workOrder, 200);
    }

    public function get_tenant_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['tenants', 'tenant_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function get_owner_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['owners', 'owner_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function SendMessage(Request $request)
    {
        $validatedData = $request->validate([
            'text' => 'required|string',
            'sender_phone_number' => 'required',
            'receiver_phone_number' => 'required',
            'work_order_id' => 'required',
            'conversation_type' => 'required',
        ]);

        // Format phone numbers ensuring proper + prefix
        $senderNumber = $validatedData['sender_phone_number'];
        $receiverNumber = $this->formatNumber($validatedData['receiver_phone_number']);

        DB::beginTransaction();

        try {

            // Save the message to the database
            Conversation::create([
                'message' => $validatedData['text'],
                'sender_number' => $senderNumber,
                'receiver_number' => $receiverNumber,
                'work_order_id' => $validatedData['work_order_id'],
                'conversation_type' => $validatedData['conversation_type'],
                'is_read' => true,
            ]);

            $twilio = new TwilioService();
            
            $twilio->sendMessage(
                $receiverNumber,
                $senderNumber,
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
            Log::error('Failed to send message: '.$e->getMessage());

            // Return an error response
            return redirect()->back()->with('error', 'Failed to send the message. Please try again.');
        }
    }

    protected function formatNumber(string $number): string
    {
        $cleanedNumber = preg_replace('/[^0-9]/', '', $number);

        if (empty($cleanedNumber)) {
            throw new InvalidArgumentException('The provided phone number is invalid.');
        }

        return '+'.$cleanedNumber;
    }

    public function delete(Conversation $conversation)
    {
        $conversation->delete();

        return redirect()->back()->with('success', 'Message deleted successfully!');
    }
}
