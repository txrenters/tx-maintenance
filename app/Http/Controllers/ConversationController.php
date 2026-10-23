<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationStoreRequest;
use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\JobberTextMessage;
use App\Models\WorkOrder;
use App\Services\ChatbotHub;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConversationController extends Controller
{
    /**
     * Ensure the current user may read the given conversation type for this work order.
     * Admins/WOC/accounting have full access. Everyone else is limited to work orders
     * visible to them via the WorkOrder scope, and vendors may only ever read the
     * WOC<->vendor ('vendor') thread — never tenant/owner/cross threads.
     */
    private function assertCanViewConversation(WorkOrder $workOrder, string $type): void
    {
        $user = auth()->user();

        // These endpoints are reached via the stateless API group where there is
        // no authenticated session. Guarding only applies when a user is resolved.
        if (! $user || $user->hasAnyRole(['admin', 'woc', 'accounting'])) {
            return;
        }

        $canSeeWorkOrder = WorkOrder::query()->scoped()->whereKey($workOrder->getKey())->exists();

        if (! $canSeeWorkOrder) {
            abort(403);
        }

        if ($user->hasRole('vendor') && $type !== 'vendor') {
            abort(403);
        }
    }

    /**
     * A signed-in owner or tenant may only post to their own party's thread.
     * Their message is carried to staff by the chatbot hub and the notification
     * feed, never by SMS, so a message filed under any other thread type has no
     * delivery path at all — and was answered with "sent" all the same.
     */
    private function assertCanSendAsParty(string $type): void
    {
        $user = auth()->user();

        if (! $this->isPortalParty($user)) {
            return;
        }

        abort_unless($type === ($user->hasRole('owner') ? 'owner' : 'tenant'), 403);
    }

    /**
     * Whether this user writes as an outside party rather than as staff. A
     * hybrid account — an owner or tenant record plus a coordinator role — works
     * the queue like any coordinator, so its messages take the texting path.
     */
    private function isPortalParty(mixed $user): bool
    {
        return $user
            && $user->hasAnyRole(['owner', 'tenant'])
            && ! $user->hasAnyRole(['admin', 'woc', 'accounting']);
    }

    public function show(WorkOrder $workOrder)
    {
        // Bundles tenant/owner threads; vendors must never reach it.
        $this->assertCanViewConversation($workOrder, 'tenant');

        $convo = $workOrder->load(['vendor_tenant_conversation.media', 'tenant_conversation.media', 'owner_conversation.media', 'vendor_conversation.media', 'vendors']);

        return inertia('Conversation/Index', [
            'title' => 'Work Order Conversation',
            'conversations' => $convo,
        ]);
    }

    public function get_vendor_tenant_conversation(WorkOrder $workOrder)
    {
        $this->assertCanViewConversation($workOrder, 'vendor_tenant');

        $workOrder->load(['tenants', 'vendor_tenant_conversation.media', 'vendors.user']);

        return response()->json($workOrder, 200);
    }

    public function get_vendor_owner_conversation(WorkOrder $workOrder)
    {
        $this->assertCanViewConversation($workOrder, 'vendor_owner');

        $workOrder->load(['owners', 'vendors.user', 'vendor_owner_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function get_vendor_conversation(WorkOrder $workOrder)
    {
        $this->assertCanViewConversation($workOrder, 'vendor');

        $workOrder->load(['vendor_conversation.media', 'vendors.user']);

        // Staff keep access to the threads of vendors who were later removed
        // from the work order; vendors themselves only ever see current
        // assignees.
        if (auth()->user()?->hasAnyRole(['admin', 'woc', 'accounting'])) {
            $workOrder->setAttribute('former_vendors', $workOrder->formerConversationVendors());
        }

        return response()->json($workOrder, 200);
    }

    public function get_tenant_conversation(WorkOrder $workOrder)
    {
        $this->assertCanViewConversation($workOrder, 'tenant');

        $workOrder->load(['tenants', 'tenant_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function get_owner_conversation(WorkOrder $workOrder)
    {
        $this->assertCanViewConversation($workOrder, 'owner');

        $workOrder->load(['owners', 'owner_conversation.media']);

        return response()->json($workOrder, 200);
    }

    public function get_conversation(Request $request)
    {
        $message = [];

        $data = $request->data ?? [];

        if (! empty($data['work_order_id'])) {
            $user = auth()->user();
            $type = $data['conversation_type'] ?? null;

            // Only return messages for work orders the user can actually see, and
            // never let a vendor read anything but the WOC<->vendor thread.
            $canSeeWorkOrder = ! $user
                || $user->hasAnyRole(['admin', 'woc', 'accounting'])
                || WorkOrder::query()->scoped()->whereKey($data['work_order_id'])->exists();

            $vendorBlocked = $user && $user->hasRole('vendor') && $type !== 'vendor';

            if ($canSeeWorkOrder && ! $vendorBlocked) {
                $message = Conversation::with(['work_order', 'media'])
                    ->where('work_order_id', $data['work_order_id'])
                    ->where('conversation_type', $type)
                    ->get();
            }

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

        $hasImages = $request->hasFile('images');

        if (empty($validatedData['text']) && ! $hasImages) {
            return redirect()->back()->withErrors([
                'message' => 'Please provide either a message or an attachment.',
            ]);
        }

        try {
            $senderNumber = filled($validatedData['sender_phone_number'] ?? null)
                ? $this->formatNumber($validatedData['sender_phone_number'])
                : null;
            $receiverNumber = filled($validatedData['receiver_phone_number'] ?? null)
                ? $this->formatNumber($validatedData['receiver_phone_number'])
                : null;
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

        $workOrder = WorkOrder::findOrFail($validatedData['work_order_id']);
        $this->assertCanViewConversation($workOrder, $validatedData['conversation_type']);
        $this->assertCanSendAsParty($validatedData['conversation_type']);
        $messageText = trim($validatedData['text'] ?? '');
        $isVendorMessage = $validatedData['conversation_type'] === 'vendor';

        $conversation = Conversation::create([
            'message' => $messageText,
            'sender_number' => $senderNumber,
            'receiver_number' => $receiverNumber,
            'work_order_id' => $validatedData['work_order_id'],
            'vendor_id' => $validatedData['vendor_id'] ?? null,
            'conversation_type' => $validatedData['conversation_type'],
            'is_read' => true,
            // A coordinator-to-vendor message is unseen by the vendor until they open their portal.
            'read_by_vendor' => ! $isVendorMessage,
            'is_mms' => $hasImages,
        ]);

        // Store all uploaded images and collect their signed URLs
        $mediaUrls = [];
        if ($hasImages) {
            foreach ($request->file('images') as $image) {
                $filename = time().'_'.$image->getClientOriginalName();
                $imagePath = $image->storeAs('conversation_images', $filename);

                $conversationMedia = ConversationMedia::create([
                    'message_id' => $conversation->id,
                    'original_url' => '',
                    'local_path' => $imagePath,
                    'content_type' => $image->getMimeType(),
                    'file_name' => $image->getClientOriginalName(),
                ]);

                $mediaUrls[] = $conversationMedia->public_url;
            }
        }

        $user = auth()->user();

        if ($this->isPortalParty($user)) {
            app(ChatbotHub::class)->queueInboundMessage($conversation);
            $this->sendNotification($conversation, $validatedData, $workOrder);

            return redirect()->back()->with('success', 'Message sent successfully!');
        }

        // No phone number on file (e.g. a portal-only vendor): the message is saved
        // and will appear in the vendor portal, but there is nothing to text.
        if (! $receiverNumber) {
            return redirect()->back()->with('success', 'Message saved. The vendor will see it in their portal.');
        }

        if (app(ChatbotHub::class)->handles($conversation)) {
            $conversation->update([
                'chatbot_direction' => 'outbound',
                'chatbot_sender_name' => $user->name,
                'chatbot_sender_email' => $user->email,
                'twilio_status' => 'pending',
            ]);

            SendConversationMessageJob::dispatch(
                $receiverNumber, $senderNumber ?? '', $messageText, $mediaUrls, $conversation->id,
            )->afterCommit();

            return redirect()->back()->with('success', 'Message queued for delivery.');
        }

        // Dispatch first job: carries the text message + first image (if any).
        // Subsequent jobs carry only the image URL (empty message body).
        $firstImageUrl = ! empty($mediaUrls) ? array_shift($mediaUrls) : null;

        SendConversationMessageJob::dispatch(
            $receiverNumber,
            $senderNumber,
            $messageText,
            $firstImageUrl,
            $conversation->id,
        );

        foreach ($mediaUrls as $imageUrl) {
            SendConversationMessageJob::dispatch(
                $receiverNumber,
                $senderNumber,
                '',
                $imageUrl,
            );
        }

        return redirect()->back()->with('success', 'Message sent. Delivery may take a moment.');
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
