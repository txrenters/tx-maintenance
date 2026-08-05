<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateThumbnail;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\WorkOrder;
use App\Rules\UploadedMediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Public, no-login owner portal — the owner-facing counterpart of the tenant
 * and vendor portals. A magic-link token (resolved by the owner.portal
 * middleware) scopes everything to one work order and one owner.
 *
 * Deliberately lean: messages, photos, and an approve/disapprove decision
 * while the work order waits on owner approval — no estimates, invoices or
 * tasks. The thread is the owner<->WOC relationship only; the owner never
 * sees or messages the vendor, which stays a WOC<->vendor relationship.
 */
class OwnerPortalController extends Controller
{
    /** Gallery source labels, phrased from the owner's point of view. */
    private const SOURCE_TENANT = 'From tenant';

    private const SOURCE_COORDINATOR = 'From work order coordinator';

    private const SOURCE_OWNER = 'From you';

    public function show(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');
        /** @var OwnerPortalToken $portalToken */
        $portalToken = $request->attributes->get('portal_token');

        $workOrder->load(['service_status', 'building', 'woc', 'requested_by']);

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

        // The tenant<->WOC thread. Its photos are of the issue itself, so the
        // owner sees them in the gallery; the messages themselves stay private
        // to that thread and are never rendered here.
        $tenantMessages = Conversation::query()
            ->withoutGlobalScopes()
            ->with('media')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->orderBy('created_at')
            ->get();

        // Photos the office has published to the owner portal, plus anything
        // this owner uploaded themselves.
        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('is_publish_to_owner_portal', true)
            ->latest()
            ->get(['id', 'title', 'type', 'filename', 'filetype', 'user_id', 'created_at']);

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
            'approval' => $this->approvalState($workOrder, $owner),
            'messages' => $messages->map(fn (Conversation $message) => [
                'id' => $message->id,
                // A message the owner sent (from their number, or through the
                // portal) renders on the right; everything else is the office.
                'from_owner' => $this->isFromOwner($message, $ownerDigits),
                'message' => $message->message,
                'created_at' => $message->created_at,
                'media' => $message->media->map(fn (ConversationMedia $media) => $this->mediaPayload($media))->values(),
            ])->values(),
            'attachments' => $this->gallery($attachments, $messages, $tenantMessages, $owner, $workOrder),
        ]);
    }

    /**
     * Everything visual the owner is allowed to see for this work order, newest
     * first: photos published to the owner portal, plus every photo exchanged
     * in the tenant<->WOC and owner<->WOC threads. The vendor thread is
     * deliberately excluded — that relationship stays with the coordinator.
     *
     * @param  Collection<int, Attachments>  $attachments
     * @param  Collection<int, Conversation>  $ownerMessages
     * @param  Collection<int, Conversation>  $tenantMessages
     * @return Collection<int, array<string, mixed>>
     */
    private function gallery(
        Collection $attachments,
        Collection $ownerMessages,
        Collection $tenantMessages,
        Owner $owner,
        WorkOrder $workOrder,
    ): Collection {
        $ownerDigits = Conversation::lastTenDigits(
            filled($owner->mobile) ? $owner->mobile : $owner->phone
        );

        $tenant = $workOrder->requested_by;
        $tenantDigits = Conversation::lastTenDigits(
            $tenant?->mobile_phone ?: $tenant?->home_phone
        );

        $fromAttachments = $attachments->map(fn (Attachments $a) => [
            'id' => 'attachment-'.$a->id,
            'title' => $a->title,
            'type' => $a->type,
            // Anything this owner uploaded is theirs; everything else the
            // office published on their behalf.
            'source' => $a->user_id !== null && $a->user_id === $owner->user_id
                ? self::SOURCE_OWNER
                : self::SOURCE_COORDINATOR,
            'url' => asset('storage/'.$a->filename),
            'is_image' => $this->looksLikeImage($a->filetype, $a->filename),
            'is_video' => str_starts_with((string) $a->filetype, 'video/'),
            'created_at' => $a->created_at,
        ]);

        $fromMessages = $ownerMessages->concat($tenantMessages)
            ->flatMap(fn (Conversation $message) => $message->media->map(
                fn (ConversationMedia $media) => $this->mediaPayload($media) + [
                    'title' => $media->file_name ?: 'Photo',
                    'type' => null,
                    'source' => $this->senderLabel($message, $ownerDigits, $tenantDigits),
                    'created_at' => $message->created_at,
                ]
            ));

        return $fromAttachments
            ->concat($fromMessages)
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Who sent this photo, from the owner's point of view. Anything not
     * traceable to the owner or the tenant came from the coordinator, which is
     * true of every outbound message on both threads.
     */
    private function senderLabel(Conversation $message, ?string $ownerDigits, ?string $tenantDigits): string
    {
        if ($message->conversation_type === 'owner') {
            return $this->isFromOwner($message, $ownerDigits)
                ? self::SOURCE_OWNER
                : self::SOURCE_COORDINATOR;
        }

        $senderDigits = Conversation::lastTenDigits($message->sender_number);

        return $tenantDigits !== null && $senderDigits === $tenantDigits
            ? self::SOURCE_TENANT
            : self::SOURCE_COORDINATOR;
    }

    /**
     * A conversation attachment for the front end. Conversation files live on
     * the private disk, so they are served through their signed route rather
     * than a public storage URL.
     *
     * @return array<string, mixed>
     */
    private function mediaPayload(ConversationMedia $media): array
    {
        return [
            'id' => 'media-'.$media->id,
            'url' => $media->local_path ? $media->public_url : $media->original_url,
            'content_type' => $media->content_type,
            'file_name' => $media->file_name,
            'is_image' => $this->looksLikeImage($media->content_type, $media->file_name),
            'is_video' => str_starts_with((string) $media->content_type, 'video/')
                || (bool) preg_match('/\.(mp4|mov|m4v|3gp|3gpp|webm)$/i', (string) $media->file_name),
        ];
    }

    /**
     * Whether a file is an image, by mime type or, for rows whose mime was
     * never detected, by extension.
     */
    private function looksLikeImage(?string $mime, ?string $filename): bool
    {
        return str_starts_with((string) $mime, 'image/')
            || (bool) preg_match('/\.(jpe?g|png|gif|webp|heic)$/i', (string) $filename);
    }

    /**
     * The owner's approve/disapprove state for this work order: whether it is
     * currently waiting on their approval (a brand-new work order), and any
     * decision this owner already sent through the portal (latest wins, so an
     * owner who calls the office to change their mind can be re-asked by
     * simply moving the status back to New).
     *
     * @return array{requested: bool, decision: ?string, decided_at: ?string}
     */
    private function approvalState(WorkOrder $workOrder, Owner $owner): array
    {
        $latestDecision = Activity::query()
            ->where('event', 'owner_portal_approval')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->where('properties->owner_id', $owner->id)
            ->latest('id')
            ->first();

        return [
            // Only brand-new work orders ask, so the owner approves or
            // declines straight from the intake notification link.
            'requested' => strtolower((string) $workOrder->service_status?->name) === 'new',
            'decision' => $latestDecision?->properties['decision'] ?? null,
            'decided_at' => $latestDecision?->created_at?->toDateTimeString(),
        ];
    }

    /**
     * Record the owner's approve/disapprove decision.
     *
     * The decision lands in the two places the coordinator already watches —
     * the owner conversation thread (as an unread inbound message) and the
     * notification bell (its own activity event) — which is exactly what the
     * portal's heads-up label promises the owner. The service status is left
     * for the coordinator to move; the portal only reports the decision.
     */
    public function submitApproval(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Owner $owner */
        $owner = $request->attributes->get('portal_owner');
        /** @var OwnerPortalToken $portalToken */
        $portalToken = $request->attributes->get('portal_token');

        $validated = $request->validate([
            'decision' => 'required|in:approved,disapproved',
        ]);

        $approved = $validated['decision'] === 'approved';

        $workOrder->loadMissing('woc.wocNumber.twilioPhoneNumber');

        $ownerNumber = $workOrder->normalizedOwnerPhone($owner) ?: 'portal';
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        $ownerName = trim((string) ($owner->first_name.' '.$owner->last_name)) ?: (string) $owner->name;

        DB::beginTransaction();

        try {
            $conversation = Conversation::create([
                'message' => $approved
                    ? 'I approve this work order. — sent from the owner portal'
                    : 'I do not approve this work order. — sent from the owner portal',
                'sender_number' => $ownerNumber,
                'receiver_number' => $wocNumber ?: null,
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'conversation_type' => 'owner',
                'is_read' => false,
                'read_by_owner' => true,
                'is_mms' => false,
            ]);

            activity()
                ->performedOn($workOrder)
                ->event('owner_portal_approval')
                ->withProperties([
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'owner_id' => $owner->id,
                    'decision' => $validated['decision'],
                    'conversation_id' => $conversation->id,
                    'message' => $approved
                        ? $ownerName.' approved this work order from the owner portal.'
                        : $ownerName.' did NOT approve this work order — please follow up.',
                    'source' => 'owner_portal',
                    'read' => false,
                ])
                ->log('Work Order #'.$workOrder->work_order_no.($approved
                    ? ' - Approved by Owner'
                    : ' - Owner Did Not Approve'));

            DB::commit();

            $portalToken->markResponded();

            return back()->with('success', $approved
                ? 'Approved — your work order coordinator has been notified.'
                : 'Got it — your work order coordinator has been notified.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Owner portal approval failed', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not record your decision. Please try again.']);
        }
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
                GenerateThumbnail::dispatch(Attachments::class, $attachment->id);
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
