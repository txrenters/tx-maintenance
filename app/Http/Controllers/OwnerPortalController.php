<?php

namespace App\Http\Controllers;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\WorkOrder;
use App\Rules\UploadedMediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, no-login owner portal — the owner-facing counterpart of the tenant
 * and vendor portals. A magic-link token (resolved by the owner.portal
 * middleware) scopes everything to one work order and one owner.
 *
 * Deliberately messages + photos only: no estimates, invoices, tasks or
 * approvals. The thread is the owner<->WOC relationship only; the owner never
 * sees or messages the vendor, which stays a WOC<->vendor relationship.
 */
class OwnerPortalController extends Controller
{
    public function show(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');
        /** @var OwnerPortalToken $portalToken */
        $portalToken = $request->attributes->get('portal_token');

        $workOrder->load(['service_status', 'building', 'woc']);

        // The upcoming appointment, if a vendor has set one. Read-only: it is
        // the same detail the owner is already texted, and it is what the
        // schedule follow-up is asking about.
        $appointment = $workOrder->service_schedules()
            ->withoutGlobalScopes()
            ->orderByDesc('scheduled_date')
            ->first(['scheduled_date', 'scheduled_end_date', 'status']);

        // This owner's thread with the coordinator only — never the vendor
        // thread, and never a co-owner's messages.
        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->with('media')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->forOwnerThread($owner)
            ->orderBy('created_at')
            ->get();

        $ownerDigits = Conversation::lastTenDigits(
            filled($owner->mobile) ? $owner->mobile : $owner->phone
        );

        // Photos the office has published to the owner portal, plus anything
        // this owner uploaded themselves.
        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('is_publish_to_owner_portal', true)
            ->latest()
            ->get(['id', 'title', 'type', 'filename', 'filetype', 'created_at']);

        return inertia('OwnerPortal/Show', [
            'title' => 'Work Order #'.$workOrder->work_order_no,
            'token' => $portalToken->token,
            'ownerName' => trim((string) ($owner->first_name ?: $owner->name)),
            'wocName' => $workOrder->woc?->name,
            'unreadMessages' => $messages->where('read_by_owner', false)->count(),
            'workOrder' => [
                'work_order_no' => $workOrder->work_order_no,
                'description' => $workOrder->description,
                'priority' => $workOrder->priority,
                'status' => $workOrder->service_status?->name ?? $workOrder->status,
                'is_emergency' => (bool) $workOrder->is_emergency,
                'address' => $workOrder->propertyAddress() ?? '',
                'property_name' => $workOrder->building?->name,
                'category' => $workOrder->category,
                'type' => $workOrder->type,
                'location' => $workOrder->location,
                'created_date' => $workOrder->created_date,
                'coordinator' => $workOrder->woc?->name,
                'appointment' => $appointment ? [
                    'scheduled_date' => $appointment->scheduled_date,
                    'scheduled_end_date' => $appointment->scheduled_end_date,
                    'status' => $appointment->status,
                ] : null,
            ],
            'messages' => $messages->map(fn (Conversation $message) => [
                'id' => $message->id,
                // A message the owner sent (from their number, or through the
                // portal) renders on the right; everything else is the office.
                'from_owner' => $this->isFromOwner($message, $ownerDigits),
                'message' => $message->message,
                'created_at' => $message->created_at,
                'media' => $message->media->map(fn (ConversationMedia $media) => [
                    'id' => $media->id,
                    'url' => $media->local_path ? asset('storage/'.$media->local_path) : $media->original_url,
                    'content_type' => $media->content_type,
                    'file_name' => $media->file_name,
                ])->values(),
            ])->values(),
            'attachments' => $attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'url' => asset('storage/'.$a->filename),
                'is_image' => str_starts_with((string) $a->filetype, 'image/')
                    || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $a->filename),
                'created_at' => $a->created_at,
            ])->values(),
        ]);
    }

    /**
     * Record a message from the owner to the work-order coordinator.
     *
     * Stored as an inbound "owner" conversation (is_read = false) so it appears
     * in the coordinator's existing owner conversation tab. No SMS is
     * dispatched — the coordinator reads it inside the system, exactly like the
     * vendor portal.
     */
    public function sendMessage(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');
        /** @var OwnerPortalToken $portalToken */
        $portalToken = $request->attributes->get('portal_token');

        $validated = $request->validate([
            'text' => 'nullable|string|max:1600',
            'images' => 'nullable|array|max:10',
            'images.*' => ['required', 'file', 'max:51200', new UploadedMediaFile],
        ]);

        $hasImages = $request->hasFile('images');
        $messageText = trim($validated['text'] ?? '');

        if ($messageText === '' && ! $hasImages) {
            return back()->withErrors(['message' => 'Please type a message or attach a photo.']);
        }

        $workOrder->loadMissing('woc.wocNumber.twilioPhoneNumber');

        $ownerNumber = $workOrder->normalizedOwnerPhone($owner) ?: 'portal';
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        DB::beginTransaction();

        try {
            $conversation = Conversation::create([
                'message' => $messageText,
                'sender_number' => $ownerNumber,
                'receiver_number' => $wocNumber ?: null,
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'conversation_type' => 'owner',
                'is_read' => false,
                'read_by_owner' => true,
                'is_mms' => $hasImages,
            ]);

            if ($hasImages) {
                foreach ($request->file('images') as $image) {
                    $filename = time().'_'.$image->getClientOriginalName();
                    $imagePath = $image->storeAs('conversation_images', $filename);

                    ConversationMedia::create([
                        'message_id' => $conversation->id,
                        'original_url' => '',
                        'local_path' => $imagePath,
                        'content_type' => $image->getMimeType(),
                        'file_name' => $image->getClientOriginalName(),
                    ]);
                }
            }

            // Surface the message in the coordinator's notification feed, which
            // is driven by the activity log, exactly like the vendor portal.
            activity()
                ->performedOn($conversation)
                ->event('work_order_message_received')
                ->withProperties([
                    'senderNumber' => $ownerNumber,
                    'receiverNumber' => $wocNumber,
                    'message' => $messageText !== '' ? $messageText : '[image]',
                    'work_order_id' => $workOrder->id,
                    'owner_id' => $owner->id,
                    'source' => 'owner_portal',
                ])
                ->log('Work Order #'.$workOrder->work_order_no.' - New Owner Message');

            DB::commit();

            // The owner has engaged: stop the schedule follow-up for them.
            $portalToken->markResponded();

            return back()->with('success', 'Message sent to your coordinator.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Owner portal message failed', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['message' => 'Could not send your message. Please try again.']);
        }
    }

    /**
     * Upload photos of the property and sync them to PropertyWare, mirroring
     * the tenant portal upload.
     */
    public function uploadAttachments(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');
        /** @var OwnerPortalToken $portalToken */
        $portalToken = $request->attributes->get('portal_token');

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => ['required', 'file', 'max:51200', new UploadedMediaFile(['pdf'])],
        ]);

        try {
            foreach ($request->file('files') as $file) {
                $attachment = Attachments::create([
                    'title' => 'Owner photo - WO#'.$workOrder->work_order_no,
                    'filename' => $file->store('attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => 'before',
                    'work_order_id' => $workOrder->id,
                    'user_id' => $owner->user_id,
                    'is_publish_to_owner_portal' => true,
                    'created_at' => now(),
                ]);

                UploadAttachment::dispatch($attachment);
            }

            $portalToken->markResponded();

            return back()->with('success', 'Photos uploaded — thank you!');
        } catch (\Throwable $e) {
            Log::error('Owner portal attachment upload failed', [
                'work_order_id' => $workOrder->id,
                'owner_portal_token_id' => $portalToken->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not upload photos. Please try again.']);
        }
    }

    /**
     * Mark the coordinator's messages as seen by this owner.
     */
    public function markMessagesRead(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');

        Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->where('read_by_owner', false)
            ->forOwnerThread($owner)
            ->update(['read_by_owner' => true]);

        return back();
    }

    /**
     * Whether a thread message came from the owner rather than the office.
     * Portal messages are tagged with the owner id; texts are matched on the
     * sender's number.
     */
    private function isFromOwner(Conversation $message, ?string $ownerDigits): bool
    {
        if ($message->sender_number === 'portal') {
            return true;
        }

        if ($ownerDigits === null) {
            return false;
        }

        return Conversation::lastTenDigits($message->sender_number) === $ownerDigits;
    }
}
