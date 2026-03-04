<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationStoreRequest;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\JobberTextMessage;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Illuminate\Http\Request;
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
        $workOrder->load(['tenants', 'vendor_tenant_conversation.media', 'vendors.user']);

        return response()->json($workOrder, 200);
    }

    public function get_vendor_owner_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['owners', 'vendors.user', 'vendor_owner_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function get_vendor_conversation(WorkOrder $workOrder)
    {
        $workOrder->load(['vendor_conversation.media', 'vendors.user']);

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

        $data = $request->data ?? [];

        if (! empty($data['work_order_id'])) {
            $message = Conversation::with(['work_order', 'media'])
                ->where('work_order_id', $data['work_order_id'])
                ->where('conversation_type', $data['conversation_type'] ?? null)
                ->get();

        } elseif (! empty($data['jobber_id'])) {
            $query = JobberTextMessage::where('jobber_id', $data['jobber_id']);

            if (! empty($data['sender_number']) && ! empty($data['receiver_number'])) {
                $query->where(function ($q) use ($data) {
                    $q->where(function ($q2) use ($data) {
                        $q2->where('sender_number', $data['sender_number'])
                            ->where('receiver_number', $data['receiver_number']);
                    })->orWhere(function ($q2) use ($data) {
                        $q2->where('sender_number', $data['receiver_number'])
                            ->where('receiver_number', $data['sender_number']);
                    });
                });
            }

            $message = $query->get();
        } else {
            $message = collect();
        }

        return response()->json($message, 200);
    }

    public function SendMessage(ConversationStoreRequest $request)
    {
        $validatedData = $request->validated();

        if (empty($validatedData['text']) && ! $request->hasFile('image')) {
            return redirect()->back()->withErrors([
                'message' => 'Please provide either a message or an image.',
            ]);
        }

        $conversation = null;

        try {
            $senderNumber = $this->formatNumber($validatedData['sender_phone_number'] ?? '');
            $receiverNumber = $this->formatNumber($validatedData['receiver_phone_number']);
        } catch (InvalidArgumentException $e) {
            Log::error('Invalid phone number format', [
                'sender' => $validatedData['sender_phone_number'] ?? null,
                'receiver' => $validatedData['receiver_phone_number'],
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors([
                'message' => 'Invalid phone number. Please check the phone number and try again.',
            ]);
        }

        try {

            $workOrder = WorkOrder::findOrFail($validatedData['work_order_id']);

            $messageText = trim($validatedData['text'] ?? '');

            $conversation = Conversation::create([
                'message' => $messageText,
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

            $imageFullPath = '';

            if ($imagePath) {
                $imageFullPath = asset('storage/'.$imagePath);
            }

            $user = auth()->user();

            if ($user->hasRole('owner') || $user->hasRole('tenant')) {
                $this->sendNotification($conversation, $validatedData, $workOrder);

                return redirect()->back()->with('success', 'Message sent successfully!');
            }

            $twilio = new TwilioService;
            $twilioMessage = $twilio->sendMessage(
                $receiverNumber,
                $senderNumber,
                $messageText,
                $imageFullPath
            );

            $conversation->update([
                'twilio_sid' => $twilioMessage->sid ?? null,
                'twilio_status' => $twilioMessage->status ?? 'queued',
                'twilio_status_updated_at' => now(),
                'twilio_error_code' => null,
                'twilio_error_message' => null,
            ]);

            return redirect()->back()->with('success', 'Message queued with Twilio. Delivery pending.');
        } catch (\Exception $e) {
            if ($conversation) {
                $conversation->update([
                    'twilio_status' => 'failed',
                    'twilio_status_updated_at' => now(),
                    'twilio_error_code' => (string) $e->getCode(),
                    'twilio_error_message' => $e->getMessage(),
                ]);
            }

            Log::error('Failed to send message', [
                'work_order_id' => $validatedData['work_order_id'] ?? null,
                'conversation_type' => $validatedData['conversation_type'] ?? null,
                'sender' => $validatedData['sender_phone_number'] ?? null,
                'receiver' => $validatedData['receiver_phone_number'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors([
                'message' => 'Failed to queue message with Twilio. Please try again.',
            ]);
        }
    }

    protected function formatNumber(string $number): string
    {
        // Strip everything except digits
        $digits = preg_replace('/\D+/', '', $number);

        if (! $digits) {
            throw new InvalidArgumentException('The provided phone number is invalid.');
        }

        /**
         * Normalize to US format
         * - 10 digits → assume US
         * - 11 digits starting with 1 → US
         */
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        throw new InvalidArgumentException('The provided phone number must be a valid US number.');
    }

    private function sendNotification($conversation, $validatedData, $workOrder): void
    {
        activity()
            ->performedOn($conversation)
            ->event('work_order_message_received')
            ->withProperties([
                'senderNumber' => $validatedData['sender_phone_number'],
                'receiverNumber' => $this->formatNumber($validatedData['receiver_phone_number']),
                'message' => $validatedData['text'],
                'work_order_id' => $validatedData['work_order_id'],
            ])
            ->log('Work Order #'.$workOrder->work_order_no.' - New Message Received');
    }

    public function delete(Conversation $conversation)
    {
        $conversation->delete();

        return redirect()->back()->with('success', 'Message deleted successfully!');
    }
}
