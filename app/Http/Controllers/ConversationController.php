<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function get_conversation(Request $request)
    {
        $message = [];

        if($request->data['work_order_id']){
            $message = Conversation::with(['work_order','media'])
                ->where('work_order_id', $request->data['work_order_id'])
                ->where('conversation_type', $request->data['conversation_type'])
                ->get();
        }else if($request?->data['job_id']){
            $message = Conversation::where('work_order_id', $request->work_order_id)
                ->where('conversation_type', $request->conversation_type)
                ->get();
        }

        return response()->json($message, 200);
    }

    public function SendMessage(Request $request)
    {
        $validatedData = $request->validate([
            'text' => 'nullable|string|max:1600',
            'sender_phone_number' => 'required',
            'receiver_phone_number' => 'required',
            'work_order_id' => 'required',
            'conversation_type' => 'required',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120', // 5MB max
        ]);

        if (empty($validatedData['text']) && ! $request->hasFile('image')) {
            return redirect()->back()->with('error', 'Please provide either a message or an image.');
        }

        $senderNumber = $validatedData['sender_phone_number'];
        $receiverNumber = $this->formatNumber($validatedData['receiver_phone_number']);

        DB::beginTransaction();

        try {
            $conversation = Conversation::create([
                'message' => $validatedData['text'] ?? '',
                'sender_number' => $senderNumber,
                'receiver_number' => $receiverNumber, 
                'work_order_id' => $validatedData['work_order_id'],
                'conversation_type' => $validatedData['conversation_type'],
                'is_read' => true,
                'is_mms' => $request->hasFile('image'), // Set MMS flag if image is present
            ]);

            $imagePath = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $originalName = $image->getClientOriginalName();
                $filename = time().'_'.$originalName;

                // Store image in storage/app/public/conversation_images
                $imagePath = $image->storeAs('conversation_images', $filename, 'public');

                // Save image info to conversation_medias table
                ConversationMedia::create([
                    'message_id' => $conversation->id,
                    'original_url' => '', // We're storing locally, no original URL from external source
                    'local_path' => $imagePath,
                    'content_type' => $image->getMimeType(),
                    'file_name' => $originalName,
                ]);
            }

            $twilio = new TwilioService;

            $messageContent = $validatedData['text'] ?? '';

            $imageFullPath = '';

            if ($imagePath) {
                $imageFullPath = asset('storage/'.$imagePath);
            }

            $twilio->sendMessage(
                $receiverNumber,
                $senderNumber,
                $messageContent,
                $imageFullPath
            );
            
            DB::commit();
            return redirect()->back()->with('success', 'Message sent successfully!');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to send message: '.$e->getMessage());

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
