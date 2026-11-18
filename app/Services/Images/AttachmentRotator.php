<?php

namespace App\Services\Images;

use App\Models\Attachments;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded photo a quarter turn and keeps everything that hangs off
 * it in step. The rotated copy is written under a NEW name, so no browser,
 * portal tab or CDN can keep showing the sideways version from cache; the old
 * file and its thumbnail are removed and a fresh thumbnail is generated.
 *
 * PropertyWare keeps the copy it was given at upload time — rotation is local
 * only, and re-uploading would leave a duplicate document on the PW side.
 */
class AttachmentRotator
{
    public const CLOCKWISE = 'clockwise';

    public const COUNTERCLOCKWISE = 'counterclockwise';

    public function __construct(
        private ImageProcessor $processor,
        private ThumbnailService $thumbnails,
    ) {}

    /**
     * Only photos GD can decode qualify: PDFs, video, HEIC and SVG never do.
     */
    public function canRotate(Attachments $attachment): bool
    {
        return (string) $attachment->filename !== ''
            && $this->processor->supports((string) $attachment->filetype);
    }

    public function rotate(Attachments $attachment, string $direction, string $disk = 'public'): bool
    {
        $degrees = match ($direction) {
            self::CLOCKWISE => 90,
            self::COUNTERCLOCKWISE => 270,
            default => null,
        };

        if ($degrees === null || ! $this->canRotate($attachment)) {
            return false;
        }

        $storage = Storage::disk($disk);
        $original = (string) $attachment->filename;

        if (! $storage->exists($original)) {
            return false;
        }

        $bytes = $this->processor->rotate($storage->path($original), $degrees);

        if ($bytes === null) {
            return false;
        }

        $rotatedPath = $this->rotatedPathFor($original);

        if ($storage->put($rotatedPath, $bytes) === false) {
            Log::warning('Rotated attachment could not be written to storage', [
                'id' => $attachment->getKey(),
                'path' => $rotatedPath,
            ]);

            return false;
        }

        // The row points at the new file before anything old is removed, so a
        // failure past this point leaves a working (if duplicated) attachment.
        $this->thumbnails->delete($attachment, $disk);
        $attachment->forceFill(['filename' => $rotatedPath, 'thumb_path' => null])->save();

        $storage->delete($original);

        $this->thumbnails->generate($attachment, 'filename', $disk);

        return true;
    }

    /**
     * attachments/9fA2xK.jpg -> attachments/<fresh random name>.jpg — same
     * folder and extension, so the PropertyWare filename (which embeds only the
     * extension) and every path-based rule keep matching.
     */
    private function rotatedPathFor(string $original): string
    {
        $normalized = str_replace('\\', '/', $original);
        $directory = trim(dirname($normalized), '/');
        $extension = pathinfo($normalized, PATHINFO_EXTENSION);

        $name = Str::random(40).($extension !== '' ? '.'.$extension : '');

        return ($directory === '' || $directory === '.') ? $name : $directory.'/'.$name;
    }
}
