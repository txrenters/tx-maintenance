<?php

namespace App\Services;

use App\Jobs\GenerateThumbnail;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies photos and videos a tenant sends through their conversation thread —
 * an MMS reply or a tenant-portal chat message — into the work order's
 * attachments, so they show up on the staff Attachments tab as Before Pictures
 * instead of living only inside the message history.
 *
 * Mirrored rows deliberately stay OUT of the tenant portal gallery (both
 * publish/uploaded flags off): the portal already renders the conversation
 * photo itself, and surfacing the mirror too would show every picture twice.
 *
 * Mirroring is best-effort by design: a failure is logged and skipped, never
 * thrown, because it always runs on the back of message ingestion — losing an
 * inbound text over a file copy would be a far worse bug than a missing mirror.
 */
class TenantPhotoMirrorService
{
    /**
     * Mirror the given media rows of a tenant conversation message into the
     * work order's attachments.
     *
     * @param  iterable<int, ConversationMedia>  $media
     * @param  bool  $syncToPropertyWare  Push each mirrored file to PropertyWare like a portal upload. Off for backfills so a deploy can't burst-upload history.
     * @param  bool  $markViewed  Stamp the mirror as already seen by staff. On for backfills — staff have had these photos in the thread all along.
     * @return array<int, Attachments> the attachments that were created
     */
    public function mirrorForConversation(
        Conversation $conversation,
        iterable $media,
        bool $syncToPropertyWare = true,
        bool $markViewed = false
    ): array {
        if ($conversation->conversation_type !== 'tenant' || ! $conversation->work_order_id) {
            return [];
        }

        // Resolved unscoped: mirroring happens from webhooks and migrations,
        // where no authenticated user should be able to narrow the lookup.
        $workOrder = WorkOrder::query()
            ->withoutGlobalScopes()
            ->with('requested_by')
            ->find($conversation->work_order_id);

        if (! $workOrder) {
            return [];
        }

        $created = [];

        foreach ($media as $item) {
            try {
                $attachment = $this->mirrorOne($workOrder, $item, $markViewed);

                if (! $attachment) {
                    continue;
                }

                GenerateThumbnail::dispatch(Attachments::class, $attachment->id);

                if ($syncToPropertyWare) {
                    UploadAttachment::dispatch($attachment);
                }

                $created[] = $attachment;
            } catch (\Throwable $e) {
                Log::warning('Tenant photo mirror failed', [
                    'conversation_id' => $conversation->id,
                    'conversation_media_id' => $item->id ?? null,
                    'work_order_id' => $workOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    private function mirrorOne(WorkOrder $workOrder, ConversationMedia $media, bool $markViewed): ?Attachments
    {
        if (! $this->isPhotoOrVideo($media)) {
            return null;
        }

        $contents = $this->readSourceFile($media);

        if ($contents === null) {
            return null;
        }

        $path = 'attachments/'.Str::random(40).'.'.$this->extensionFor($media);

        Storage::disk('public')->put($path, $contents);

        return Attachments::create([
            'title' => 'Tenant photo - WO#'.($workOrder->work_order_no ?? $workOrder->id),
            'filename' => $path,
            'filetype' => $media->content_type,
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $workOrder->requested_by?->user_id,
            'is_publish_to_owner_portal' => true,
            'is_publish_to_tenant_portal' => false,
            'uploaded_via_tenant_portal' => false,
            'viewed_by_staff_at' => $markViewed ? now() : null,
            'created_at' => $media->created_at ?? now(),
        ]);
    }

    /**
     * Only visual media belongs in Before Pictures — voice notes, vcards and
     * other MMS payloads stay conversation-only.
     */
    private function isPhotoOrVideo(ConversationMedia $media): bool
    {
        $mime = (string) $media->content_type;

        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            return true;
        }

        return (bool) preg_match(
            '/\.(jpe?g|png|gif|webp|heic|mp4|mov|m4v|3gp|3gpp|webm)$/i',
            (string) $media->file_name
        );
    }

    /**
     * Conversation files are written to the private disk by MediaService, and
     * to the default disk by the portal chat — check both before giving up.
     */
    private function readSourceFile(ConversationMedia $media): ?string
    {
        $path = (string) $media->local_path;

        if ($path === '') {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        if (Storage::exists($path)) {
            return Storage::get($path);
        }

        return null;
    }

    private function extensionFor(ConversationMedia $media): string
    {
        $fromName = strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION));

        if ($fromName !== '' && $fromName !== 'bin') {
            return $fromName;
        }

        return match ((string) $media->content_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/3gpp' => '3gp',
            'video/webm' => 'webm',
            default => 'bin',
        };
    }
}
