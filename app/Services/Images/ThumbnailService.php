<?php

namespace App\Services\Images;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Writes a WebP thumbnail next to the original on the same disk, so nginx keeps
 * serving it straight from the public/storage symlink with no route or
 * middleware involved.
 */
class ThumbnailService
{
    public function __construct(private ImageProcessor $processor) {}

    /**
     * attachments/9fA2xK.jpg -> attachments/thumbs/9fA2xK.webp
     */
    public function thumbPathFor(string $originalPath): string
    {
        $normalized = str_replace('\\', '/', $originalPath);
        $directory = trim(dirname($normalized), '/');
        $name = pathinfo($normalized, PATHINFO_FILENAME);

        $prefix = ($directory === '' || $directory === '.') ? 'thumbs' : $directory.'/thumbs';

        return $prefix.'/'.$name.'.webp';
    }

    /**
     * Generate and persist a thumbnail for the model. Returns false — never
     * throws — for anything unprocessable (PDFs, video, HEIC, SVG, corrupt or
     * oversized files), leaving thumb_path null so the accessor falls back to
     * the original.
     */
    public function generate(Model $model, string $sourceColumn = 'filename', string $disk = 'public'): bool
    {
        $source = (string) ($model->{$sourceColumn} ?? '');

        if ($source === '') {
            return false;
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($source)) {
            return false;
        }

        $bytes = $this->processor->thumbnail(
            $storage->path($source),
            (int) config('thumbnails.max_width'),
            (int) config('thumbnails.max_height'),
        );

        if ($bytes === null) {
            return false;
        }

        $thumbPath = $this->thumbPathFor($source);

        if ($storage->put($thumbPath, $bytes) === false) {
            Log::warning('Thumbnail could not be written to storage', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'thumb_path' => $thumbPath,
            ]);

            return false;
        }

        $model->forceFill(['thumb_path' => $thumbPath])->saveQuietly();

        return true;
    }

    /**
     * True when the source is a supported image that momentarily failed to
     * decode. UploadAttachment rewrites the original in place via the spatie
     * optimizer chain, which is not atomic, so a concurrent read can catch a
     * half-written file — worth one retry rather than losing the thumbnail.
     */
    public function looksTransient(Model $model, string $sourceColumn = 'filename', string $disk = 'public'): bool
    {
        $source = (string) ($model->{$sourceColumn} ?? '');

        if ($source === '' || $model->thumb_path) {
            return false;
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($source)) {
            return false;
        }

        return $this->processor->supports((string) $model->filetype)
            && $this->processor->probe($storage->path($source)) === null;
    }

    public function delete(Model $model, string $disk = 'public'): void
    {
        $thumbPath = (string) ($model->thumb_path ?? '');

        if ($thumbPath === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($thumbPath);
        } catch (\Throwable $e) {
            Log::warning('Thumbnail could not be deleted', [
                'thumb_path' => $thumbPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
