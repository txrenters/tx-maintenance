<?php

namespace App\Services;

use App\Models\Attachments;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderJobberNoteFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetches the photos on a Jobber note onto the work order's Attachments tab.
 *
 * Downloaded rather than linked: Jobber serves note files from ActiveStorage
 * behind short-TTL signed URLs, so a stored url renders for about an hour and
 * is a broken image afterwards — a failure that looks perfect in testing and
 * surfaces days later in production.
 *
 * The rows land in `attachments`, which is what the Attachments tab reads, and
 * they are deliberately inert everywhere else:
 *
 *  - `is_publish_to_owner_portal` / `is_publish_to_tenant_portal` stay false,
 *    which is what the two portals filter on;
 *  - `uploaded_via_tenant_portal` stays false — it is a second way into the
 *    tenant portal, not just a provenance label;
 *  - UploadAttachment is never dispatched, and `jobber_note_file_gid` keeps
 *    RepairAttachmentPwUploads' daily sweep off them, so nothing reaches
 *    PropertyWare;
 *  - `type` is 'attachment': the tab renders nothing outside its three enum
 *    buckets, and 'before'/'after' would force an owner-portal publish on the
 *    PropertyWare copy if one were ever made.
 *
 * Every failure here is logged and swallowed. A photo that will not download
 * must never cost us the note it belongs to.
 */
class JobberNotePhotoDownloader
{
    /**
     * Refuse anything larger. Crew photos are phone-camera sized; something
     * far bigger is a video or a mistake, and neither belongs on the tab.
     */
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const DIRECTORY = 'attachments';

    /**
     * Store one of Jobber's note files as a work order attachment, unless it
     * is already here.
     *
     * Returns the stored key on the public disk, or null when the file is
     * still processing, carries no usable url, or the download failed — each
     * of which is simply retried on the next sync.
     *
     * @param  array<string, mixed>  $file
     */
    public function store(WorkOrderJobberNote $note, array $file): ?string
    {
        $gid = $file['id'] ?? null;

        if (! is_string($gid) || $gid === '') {
            return null;
        }

        // Jobber is still making its renditions; the URLs are not usable yet.
        if (($file['status'] ?? null) === 'PROCESSING') {
            return null;
        }

        // Already downloaded for this work order: the dedupe that keeps a
        // re-sync free, and what stops the tab filling with copies.
        $existing = Attachments::withoutGlobalScopes()
            ->where('work_order_id', $note->work_order_id)
            ->where('jobber_note_file_gid', $gid)
            ->first();

        if ($existing !== null) {
            $this->link($note, $gid, $existing);

            return $existing->filename;
        }

        $url = $file['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $fileName = (string) ($file['fileName'] ?? '');
        $stored = $this->download($url, $fileName, $note->id);

        if ($stored === null) {
            return null;
        }

        // Files.vue calls filetype.startsWith() unguarded, so a null here
        // throws and blanks the whole tab. Fall back to a sane default.
        $contentType = $file['contentType'] ?? null;
        $contentType = is_string($contentType) && $contentType !== ''
            ? $contentType
            : 'application/octet-stream';

        $attachment = Attachments::withoutGlobalScopes()->create([
            'title' => $fileName !== '' ? $fileName : 'Jobber photo',
            'filename' => $stored,
            'filetype' => $contentType,
            'type' => 'attachment',
            'work_order_id' => $note->work_order_id,
            // No local uploader: this came from a technician in Jobber, whose
            // name is on the note rather than in our users table.
            'user_id' => null,
            'jobber_note_file_gid' => $gid,
            // The crew's own photos are not a PropertyWare document and are
            // not portal content; these are the columns both portals filter
            // on, set explicitly rather than left to their defaults.
            'is_publish_to_owner_portal' => false,
            'is_publish_to_tenant_portal' => false,
            'uploaded_via_tenant_portal' => false,
            // Badge it as new: a photo arriving from the field is exactly the
            // kind of thing a coordinator should be shown.
            'viewed_by_staff_at' => null,
        ]);

        $this->link($note, $gid, $attachment);

        return $stored;
    }

    /**
     * Record which note brought a photo in. The attachment is what the tab
     * renders; this row is the bookkeeping that lets a note's photos be found
     * again when the note is deleted in Jobber.
     */
    private function link(WorkOrderJobberNote $note, string $gid, Attachments $attachment): void
    {
        WorkOrderJobberNoteFile::updateOrCreate(
            ['work_order_jobber_note_id' => $note->id, 'jobber_file_gid' => $gid],
            ['attachment_id' => $attachment->id]
        );
    }

    /**
     * Drop a note's downloaded photos from disk and from the Attachments tab
     * when the note goes away in Jobber, so nothing is left pointing at a note
     * that no longer exists.
     */
    public function forget(WorkOrderJobberNote $note): void
    {
        $attachmentIds = $note->files()->pluck('attachment_id')->filter()->all();

        if ($attachmentIds === []) {
            return;
        }

        $rows = Attachments::withoutGlobalScopes()->whereIn('id', $attachmentIds)->get();

        foreach ($rows as $row) {
            try {
                if (filled($row->filename)) {
                    Storage::disk('public')->delete($row->filename);
                }

                $row->delete();
            } catch (\Throwable $e) {
                Log::warning('Could not remove a Jobber note photo', [
                    'attachment_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fetch the bytes and put them on the public disk, or null on any refusal.
     */
    private function download(string $url, string $fileName, int $noteId): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->failed()) {
                Log::warning('Jobber note photo download failed', [
                    'work_order_jobber_note_id' => $noteId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->body();

            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                Log::warning('Jobber note photo was empty or too large', [
                    'work_order_jobber_note_id' => $noteId,
                    'bytes' => strlen($body),
                ]);

                return null;
            }

            $path = self::DIRECTORY.'/'.Str::uuid()->toString().$this->extension($fileName, $response->header('Content-Type'));

            Storage::disk('public')->put($path, $body);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Jobber note photo could not be fetched', [
                'work_order_jobber_note_id' => $noteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * An extension for the stored file, from Jobber's file name where it has
     * one and the served content type otherwise. Files.vue splits on it for
     * the tile icon, so a name without one is worth filling in.
     */
    private function extension(string $fileName, ?string $contentType): string
    {
        $fromName = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,5}$/', $fromName) === 1) {
            return '.'.$fromName;
        }

        return match (true) {
            str_contains((string) $contentType, 'png') => '.png',
            str_contains((string) $contentType, 'gif') => '.gif',
            str_contains((string) $contentType, 'webp') => '.webp',
            str_contains((string) $contentType, 'pdf') => '.pdf',
            str_contains((string) $contentType, 'jpeg'),
            str_contains((string) $contentType, 'jpg') => '.jpg',
            default => '',
        };
    }
}
