<?php

namespace App\Http\Controllers;

use App\Ai\TenantEasyFixCriteria;
use App\Jobs\GenerateThumbnail;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Rules\UploadedMediaFile;
use App\Services\TenantEasyFixService;
use App\Services\TenantPhotoMirrorService;
use App\Services\TenantRequestIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, no-login tenant portal — the tenant-facing counterpart of the owner
 * and vendor portals. A magic-link token (resolved by the tenant.portal
 * middleware) scopes everything to one work order; the tenant can view the
 * request and its appointment, message their coordinator, upload photos of the
 * issue, and mark themselves done.
 *
 * Deliberately messages + photos only: no estimates, invoices, tasks or costs.
 * The thread is the tenant<->WOC relationship only; the tenant never sees or
 * messages the vendor or the owner, which stay WOC-owned relationships.
 */
class TenantPortalController extends Controller
{
    /** Gallery source labels, phrased from the tenant's point of view. */
    private const SOURCE_TENANT = 'From you';

    private const SOURCE_COORDINATOR = 'From your coordinator';

    public function show(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        $workOrder->load(['service_status', 'building', 'woc', 'requested_by']);

        $tenant = $workOrder->requested_by;

        // The upcoming appointment, if one has been set. Read-only: it is the
        // same detail the tenant is already texted, and it is what the schedule
        // follow-up is asking about.
        $appointment = $workOrder->service_schedules()
            ->withoutGlobalScopes()
            ->orderByDesc('scheduled_date')
            ->first(['scheduled_date', 'scheduled_end_date', 'status']);

        // This tenant's thread with the coordinator only — never the owner or
        // vendor threads.
        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->with('media')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->orderBy('created_at')
            ->get();

        $tenantDigits = Conversation::lastTenDigits(
            $tenant?->mobile_phone ?: $tenant?->home_phone
        );

        // Photos the office has published to the tenant portal, plus anything
        // the tenant uploaded themselves. A tenant must never see internal or
        // vendor files.
        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where(function ($query) {
                $query->where('is_publish_to_tenant_portal', true)
                    ->orWhere('uploaded_via_tenant_portal', true);
            })
            ->latest()
            ->get(['id', 'title', 'type', 'filename', 'filetype', 'uploaded_via_tenant_portal', 'created_at']);

        $isHoa = $uploadToken->purpose === TenantUploadToken::PURPOSE_HOA_VIOLATION;

        // The handbook how-to for a request judged a tenant easy fix at
        // intake, shown whichever link the tenant opened.
        $easyFix = app(TenantEasyFixService::class)->verdictFor($workOrder->easy_fix_key);
        $easyFixCard = $easyFix !== null && $easyFix['sendable'] && $easyFix['kind'] === TenantEasyFixCriteria::KIND_EASY_FIX
            ? [
                'label' => $easyFix['item']['label'],
                'video_url' => $easyFix['item']['video_url'],
                'tip' => TenantEasyFixService::tipSentence((string) ($easyFix['item']['tip'] ?? '')),
            ]
            : null;

        return inertia('TenantPortal/Show', [
            'easyFix' => $easyFixCard,
            'title' => 'Service Request #'.$workOrder->work_order_no,
            'token' => $uploadToken->token,
            'tenantName' => trim((string) ($tenant?->first_name ?? '')),
            'wocName' => $workOrder->woc?->name,
            'completed' => $uploadToken->isCompleted(),
            'isHoa' => $isHoa,
            // Deliberately not gated on the request being open: a closed request
            // is the likeliest moment a tenant notices something new, and this
            // link is the only way in they have. Without a PropertyWare building
            // there is nothing to hang a new request on, so hide it instead.
            'canCreateRequest' => (bool) config('services.tenant_portal.create_request_enabled')
                && filled($workOrder->building_id),
            'deadline' => $isHoa
                ? $uploadToken->hoa_deadline_at?->timezone('America/Chicago')->format('l, F j, Y')
                : null,
            'unreadMessages' => $messages->where('read_by_tenant', false)->count(),
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
                // A message the tenant sent (from their number, or through the
                // portal) renders on the right; everything else is the office.
                'from_tenant' => $this->isFromTenant($message, $tenantDigits),
                'message' => $message->message,
                'created_at' => $message->created_at,
                'media' => $message->media->map(fn (ConversationMedia $media) => $this->mediaPayload($media))->values(),
            ])->values(),
            'attachments' => $this->gallery($attachments, $messages, $tenantDigits),
        ]);
    }

    /**
     * Everything visual the tenant is allowed to see for this work order, newest
     * first: photos published to the tenant portal, plus every photo exchanged
     * in their own thread with the coordinator. The owner and vendor threads are
     * deliberately excluded.
     *
     * @param  Collection<int, Attachments>  $attachments
     * @param  Collection<int, Conversation>  $tenantMessages
     * @return Collection<int, array<string, mixed>>
     */
    private function gallery(Collection $attachments, Collection $tenantMessages, ?string $tenantDigits): Collection
    {
        $fromAttachments = $attachments->map(fn (Attachments $a) => [
            'id' => 'attachment-'.$a->id,
            'title' => $a->title,
            'type' => $a->type,
            // Anything that came in through this portal is the tenant's own;
            // everything else the office published for them.
            'source' => $a->uploaded_via_tenant_portal
                ? self::SOURCE_TENANT
                : self::SOURCE_COORDINATOR,
            'url' => asset('storage/'.$a->filename),
            'is_image' => $this->looksLikeImage($a->filetype, $a->filename),
            'is_video' => str_starts_with((string) $a->filetype, 'video/'),
            'created_at' => $a->created_at,
        ]);

        $fromMessages = $tenantMessages->flatMap(fn (Conversation $message) => $message->media->map(
            fn (ConversationMedia $media) => $this->mediaPayload($media) + [
                'title' => $media->file_name ?: 'Photo',
                'type' => null,
                'source' => $this->isFromTenant($message, $tenantDigits)
                    ? self::SOURCE_TENANT
                    : self::SOURCE_COORDINATOR,
                'created_at' => $message->created_at,
            ]
        ));

        return $fromAttachments
            ->concat($fromMessages)
            ->sortByDesc('created_at')
            ->values();
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
     * Whether a thread message came from the tenant rather than the office.
     * Portal messages are tagged with a 'portal' sender; texts are matched on
     * the sender's number.
     */
    private function isFromTenant(Conversation $message, ?string $tenantDigits): bool
    {
        if ($message->sender_number === 'portal') {
            return true;
        }

        if ($tenantDigits === null) {
            return false;
        }

        return Conversation::lastTenDigits($message->sender_number) === $tenantDigits;
    }

    /**
     * Record a message from the tenant to the work-order coordinator.
     *
     * Stored as an inbound "tenant" conversation (is_read = false) so it appears
     * in the coordinator's existing tenant conversation tab. No SMS is
     * dispatched — the coordinator reads it inside the system, exactly like the
     * owner and vendor portals.
     */
    public function sendMessage(Request $request, TenantPhotoMirrorService $photoMirror)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

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

        $workOrder->loadMissing(['requested_by', 'woc.wocNumber.twilioPhoneNumber']);

        $tenant = $workOrder->requested_by;
        $tenantNumber = $tenant?->mobile_phone ?: $tenant?->home_phone ?: 'portal';
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_from')
            ?: config('services.twilio.from');

        DB::beginTransaction();

        try {
            $conversation = Conversation::create([
                'message' => $messageText,
                'sender_number' => $tenantNumber,
                'receiver_number' => $wocNumber ?: null,
                'work_order_id' => $workOrder->id,
                'conversation_type' => 'tenant',
                'is_read' => false,
                'read_by_tenant' => true,
                'is_mms' => $hasImages,
            ]);

            $storedMedia = [];

            if ($hasImages) {
                foreach ($request->file('images') as $image) {
                    $filename = time().'_'.$image->getClientOriginalName();
                    $imagePath = $image->storeAs('conversation_images', $filename);

                    $storedMedia[] = ConversationMedia::create([
                        'message_id' => $conversation->id,
                        'original_url' => '',
                        'local_path' => $imagePath,
                        'content_type' => $image->getMimeType(),
                        'file_name' => $image->getClientOriginalName(),
                    ]);
                }
            }

            // Surface the message in the coordinator's notification feed, which
            // is driven by the activity log, exactly like the owner portal.
            activity()
                ->performedOn($conversation)
                ->event('work_order_message_received')
                ->withProperties([
                    'senderNumber' => $tenantNumber,
                    'receiverNumber' => $wocNumber,
                    'message' => $messageText !== '' ? $messageText : '[image]',
                    'work_order_id' => $workOrder->id,
                    'source' => 'tenant_portal',
                ])
                ->log('Work Order #'.$workOrder->work_order_no.' - New Tenant Message');

            DB::commit();

            // Photos sent through the portal chat belong on the staff
            // Attachments tab too. After the commit AND swallowed: the
            // message is already sent, so a mirror failure reaching the
            // outer catch would show the tenant an error for a message
            // that went through — and invite duplicate resends.
            if ($storedMedia !== []) {
                try {
                    $photoMirror->mirrorForConversation($conversation, $storedMedia);
                } catch (\Throwable $mirrorError) {
                    Log::warning('Tenant portal photo mirror failed; message sent without Attachments copy.', [
                        'work_order_id' => $workOrder->id,
                        'conversation_id' => $conversation->id,
                        'error' => $mirrorError->getMessage(),
                    ]);
                }
            }

            // The tenant has engaged: stop the schedule follow-up for them.
            $uploadToken->markResponded();

            return back()->with('success', 'Message sent to your coordinator.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Tenant portal message failed', [
                'work_order_id' => $workOrder->id,
                'tenant_upload_token_id' => $uploadToken->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['message' => 'Could not send your message. Please try again.']);
        }
    }

    /**
     * Mark the coordinator's messages as seen by this tenant.
     */
    public function markMessagesRead(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');

        Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->where('read_by_tenant', false)
            ->update(['read_by_tenant' => true]);

        return back();
    }

    /**
     * Upload one or more photos of the issue and sync them to PropertyWare.
     * Mirrors the vendor portal upload; a successful upload also counts as
     * completing the request, which stops the reminder texts.
     */
    public function uploadAttachments(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => ['required', 'file', 'max:51200', new UploadedMediaFile(['pdf'])],
        ]);

        try {
            foreach ($request->file('files') as $file) {
                $attachment = Attachments::create([
                    'title' => 'Tenant photo - WO#'.$workOrder->work_order_no,
                    'filename' => $file->store('attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => 'before',
                    'work_order_id' => $workOrder->id,
                    'user_id' => $workOrder->requested_by?->user_id,
                    'uploaded_via_tenant_portal' => true,
                    'is_publish_to_owner_portal' => true,
                    'is_publish_to_tenant_portal' => true,
                    'created_at' => now(),
                ]);

                UploadAttachment::dispatch($attachment);
                GenerateThumbnail::dispatch(Attachments::class, $attachment->id);
            }

            // Photos received: stop the reminders for this request.
            if (! $uploadToken->isCompleted()) {
                $uploadToken->update(['completed_at' => now()]);
            }

            // The tenant has engaged: stop the schedule follow-up too.
            $uploadToken->markResponded();

            return back()->with('success', 'Photos uploaded — thank you!');
        } catch (\Throwable $e) {
            Log::error('Tenant portal attachment upload failed', [
                'work_order_id' => $workOrder->id,
                'tenant_upload_token_id' => $uploadToken->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not upload photos. Please try again.']);
        }
    }

    /**
     * Open a brand new work order from this portal link. The tenant is here
     * about one request; anything else they notice broken becomes its own
     * request in PropertyWare rather than a message a coordinator has to
     * re-key by hand.
     *
     * On success the tenant is sent to the new request's own portal page — the
     * same link their confirmation text will carry, so the URL in their browser
     * and the one in their messages match.
     */
    public function storeRequest(Request $request, TenantRequestIntakeService $intake)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        if (! config('services.tenant_portal.create_request_enabled') || blank($workOrder->building_id)) {
            return back()->withErrors(['description' => 'New requests cannot be opened here right now. Please message your coordinator in the Messages tab.']);
        }

        $validated = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['required', 'file', 'max:51200', new UploadedMediaFile(['pdf'])],
        ]);

        // Opening a request takes several PropertyWare round trips, so a tenant
        // who taps twice would otherwise get two work orders. The durable
        // cooldown inside the service is the real guard; this just stops the
        // second tap of the same submission racing the first.
        $lock = Cache::lock('tenant-portal:new-request:'.$uploadToken->id, 60);

        if (! $lock->get()) {
            return back()->withErrors(['description' => 'We are still opening your last request — give it a moment.']);
        }

        try {
            $result = $intake->createForTenant(
                $workOrder,
                $validated['description'],
                $request->file('photos') ?? [],
            );
        } catch (\Throwable $e) {
            Log::error('Tenant portal new request failed', [
                'work_order_id' => $workOrder->id,
                'tenant_upload_token_id' => $uploadToken->id,
                'error' => $e->getMessage(),
            ]);

            $result = ['work_order' => null, 'token' => null, 'reason' => 'failed'];
        } finally {
            $lock->release();
        }

        if ($result['work_order'] === null || $result['token'] === null) {
            return back()->withErrors(['description' => $this->requestRefusalMessage($result['reason'] ?? 'failed')]);
        }

        // The tenant has engaged: stop the schedule follow-up on the request
        // they came in on.
        $uploadToken->markResponded();

        $new = $result['work_order'];

        return redirect()
            ->route('tenant.portal.show', $result['token']->token)
            ->with('success', 'Request #'.($new->work_order_no ?? $new->id).' is open — this page is now your new request. '
                .($workOrder->work_order_no ? 'Request #'.$workOrder->work_order_no.' is unchanged. ' : '')
                .'We have also texted you this link.');
    }

    /**
     * Every refusal ends somewhere a human can help, because the tenant has
     * just typed out a problem and must not be left with a dead end.
     */
    private function requestRefusalMessage(string $reason): string
    {
        return match ($reason) {
            'cooldown' => 'You just sent us a request — give us a few minutes before opening another one.',
            'capped' => 'You already have several open requests for this property. Message your coordinator in the Messages tab and we will help.',
            'duplicate' => 'Looks like you have already told us about this — we are on it. Message your coordinator in the Messages tab if anything has changed.',
            default => 'We could not open your request just now. Please message your coordinator in the Messages tab.',
        };
    }

    /**
     * The tenant says they are done (photos sent or issue resolved) — stop the
     * reminders. The link stays usable in case they want to add more photos.
     */
    public function complete(Request $request)
    {
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        if (! $uploadToken->isCompleted()) {
            $uploadToken->update(['completed_at' => now()]);
        }

        return back()->with('success', 'Thank you! Our team has been notified.');
    }
}
