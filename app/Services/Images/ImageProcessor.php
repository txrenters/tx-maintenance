<?php

namespace App\Services\Images;

/**
 * Swap seam for image manipulation. GdImageProcessor is the only implementation
 * today; once laravel/framework reaches >= 13.20 (which ships Illuminate\Image)
 * a LaravelImageProcessor can be bound in its place without touching callers.
 */
interface ImageProcessor
{
    /**
     * Whether this processor can decode the given mime type.
     */
    public function supports(string $mime): bool;

    /**
     * Read the image header without decoding the pixels.
     *
     * @return array{width: int, height: int, mime: string}|null null when the file is
     *                                                           missing, corrupt or truncated
     */
    public function probe(string $absolutePath): ?array;

    /**
     * Scale the image to fit inside the given box, never upscaling.
     *
     * @return string|null encoded WebP bytes, or null when the image cannot be processed
     */
    public function thumbnail(string $absolutePath, int $maxWidth, int $maxHeight): ?string;

    /**
     * Turn the image a quarter or half turn clockwise, baking any EXIF
     * orientation into the pixels first so the result matches what the
     * browser was showing.
     *
     * @param  int  $degreesClockwise  90, 180 or 270
     * @return string|null bytes re-encoded in the image's own format, or null when
     *                     the image cannot be processed
     */
    public function rotate(string $absolutePath, int $degreesClockwise): ?string;
}
