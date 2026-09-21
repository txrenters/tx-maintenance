<?php

namespace App\Services;

use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderJobberNoteFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetches a Jobber note's photos onto the public disk.
 *
 * Downloaded rather than linked: Jobber serves note files from ActiveStorage
 * behind short-TTL signed URLs, so a stored url renders for about an hour and
 * is a broken image afterwards — a failure that looks perfect in testing and
 * surfaces days later in production. Downloading also keeps the photo after
 * the note is deleted in Jobber, and needs nothing from Jobber at page render.
 *
 * Every failure here is logged and swallowed. A photo that will not download
 * must never cost us the note it belongs to.
 */
class JobberNotePhotoDownloader
{
    /**
     * Refuse anything larger. Crew photos are phone-camera sized; something
     * far bigger is a video or a mistake, and neither belongs inline.
     */
    private const MAX_BYTES = 20 * 1024 * 1024;

    private const DIRECTORY = 'jobber-note-photos';

    /**
     * Store one of Jobber's note files, unless it is already here.
     *
     * Returns the stored key on the public disk, or null when the file is
     * still processing, is not something we show, or the download failed —
     * each of which is simply retried on the next sync.
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

        $row = WorkOrderJobberNoteFile::firstOrNew([
            'work_order_jobber_note_id' => $note->id,
            'jobber_file_gid' => $gid,
        ]);

        $row->file_name = isset($file['fileName']) ? (string) $file['fileName'] : $row->file_name;
        $row->content_type = isset($file['contentType']) ? (string) $file['contentType'] : $row->content_type;
        $row->file_size = isset($file['fileSize']) && is_numeric($file['fileSize'])
            ? (int) $file['fileSize']
            : $row->file_size;

        // Already downloaded: keep the bytes we have. This is what makes a
        // re-sync cost nothing, and it is the dedupe the feature was asked for.
        if (filled($row->filename)) {
            $row->save();

            return $row->filename;
        }

        $row->save();

        $url = $file['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $stored = $this->download($url, (string) ($file['fileName'] ?? ''), $note->id);

        if ($stored === null) {
            return null;
        }

        $row->filename = $stored;
        $row->save();

        return $stored;
    }

    /**
     * Drop a note's downloaded files from disk before its rows cascade away,
     * so a note deleted in Jobber does not leave its photos orphaned there.
     */
    public function forget(WorkOrderJobberNote $note): void
    {
        foreach ($note->files()->get() as $file) {
            if (blank($file->filename)) {
                continue;
            }

            try {
                Storage::disk('public')->delete($file->filename);
            } catch (\Throwable $e) {
                Log::warning('Could not remove a Jobber note photo', [
                    'work_order_jobber_note_id' => $note->id,
                    'filename' => $file->filename,
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
     * one and the served content type otherwise. Kept so the disk stays
     * browsable and the browser is told what it is being handed.
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
