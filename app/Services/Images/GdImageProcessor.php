<?php

namespace App\Services\Images;

use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Thumbnail generation on PHP's GD extension, which production already compiles
 * --with-webp --with-jpeg --with-freetype in startup.sh. No Composer dependency
 * and no Imagick, neither of which are available on the Azure instance.
 */
class GdImageProcessor implements ImageProcessor
{
    /**
     * Allow-list, deliberately not a deny-list: UploadedMediaFile accepts any
     * image/* or video/* upload, including HEIC/HEIF and SVG, none of which GD
     * can decode (and user-supplied SVG must never reach a rasterizer). Matching
     * the set in App\Services\AttachmentOptimizer.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Upper bound on the APP1 segment read when parsing orientation by hand.
     */
    private const MAX_EXIF_BYTES = 65535;

    public function supports(string $mime): bool
    {
        $normalized = strtolower(trim(explode(';', $mime)[0]));

        return in_array($normalized, self::SUPPORTED_MIMES, true);
    }

    /**
     * @return array{width: int, height: int, mime: string}|null
     */
    public function probe(string $absolutePath): ?array
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        // Reads the header only, and returns false on corrupt or truncated data.
        // Used in preference to the client-supplied mime, which is not trustworthy.
        $info = @getimagesize($absolutePath);

        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }

        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'mime' => (string) ($info['mime'] ?? ''),
        ];
    }

    public function thumbnail(string $absolutePath, int $maxWidth, int $maxHeight): ?string
    {
        $probe = $this->probe($absolutePath);

        if ($probe === null || ! $this->supports($probe['mime'])) {
            return null;
        }

        if ((int) config('thumbnails.max_pixels') < $probe['width'] * $probe['height']) {
            Log::warning('Thumbnail skipped: image exceeds the pixel budget', [
                'path' => $absolutePath,
                'width' => $probe['width'],
                'height' => $probe['height'],
            ]);

            return null;
        }

        $previousMemoryLimit = ini_get('memory_limit');
        $source = null;
        $canvas = null;

        try {
            ini_set('memory_limit', (string) config('thumbnails.max_memory'));

            $contents = @file_get_contents($absolutePath);

            if ($contents === false || $contents === '') {
                return null;
            }

            $source = @imagecreatefromstring($contents);
            unset($contents);

            if ($source === false) {
                return null;
            }

            $source = $this->applyOrientation($source, $absolutePath, $probe['mime']);

            $width = imagesx($source);
            $height = imagesy($source);

            // Fit inside the box, and never upscale — a 100x100 image stays 100x100.
            $scale = min($maxWidth / $width, $maxHeight / $height, 1);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($canvas === false) {
                return null;
            }

            // Preserve transparency from PNG/GIF/WebP sources; WebP output has alpha.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $encoded = imagewebp($canvas, null, (int) config('thumbnails.quality'));
            $bytes = ob_get_clean();

            if (! $encoded || $bytes === false || $bytes === '') {
                return null;
            }

            return $bytes;
        } catch (\Throwable $e) {
            Log::warning('Thumbnail generation failed', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }

            if ($canvas instanceof GdImage) {
                imagedestroy($canvas);
            }

            ini_set('memory_limit', $previousMemoryLimit);
        }
    }

    /**
     * Browsers honour EXIF orientation on the original (image-orientation:
     * from-image is the CSS default) but GD drops EXIF when it re-encodes, so a
     * portrait phone photo would render sideways in the tile while the lightbox
     * looked correct. Bake the rotation into the pixels instead.
     */
    private function applyOrientation(GdImage $image, string $absolutePath, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg') {
            return $image;
        }

        $orientation = $this->readJpegOrientation($absolutePath);

        if ($orientation === null || $orientation === 1) {
            return $image;
        }

        // imagerotate turns counter-clockwise, so orientation 6 ("rotate 90 CW
        // to display") becomes a 270 degree rotation here.
        $rotation = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        $oriented = $image;

        if ($rotation !== 0) {
            $rotated = @imagerotate($image, $rotation, 0);

            if ($rotated === false) {
                return $image;
            }

            imagedestroy($image);
            $oriented = $rotated;
        }

        // Orientations 2, 4, 5 and 7 are the mirrored variants.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($oriented, IMG_FLIP_HORIZONTAL);
        }

        return $oriented;
    }

    private function readJpegOrientation(string $absolutePath): ?int
    {
        if (extension_loaded('exif')) {
            $data = @exif_read_data($absolutePath);
            $orientation = is_array($data) ? ($data['Orientation'] ?? null) : null;

            return is_numeric($orientation) ? (int) $orientation : null;
        }

        return $this->readJpegOrientationFromBytes($absolutePath);
    }

    /**
     * Minimal EXIF Orientation reader, used when ext-exif is unavailable — it is
     * not loaded locally and startup.sh never installs it, so relying on the
     * extension alone would silently ship sideways thumbnails. Walks the JPEG
     * marker chain to the APP1/Exif segment and reads IFD0 tag 0x0112.
     */
    private function readJpegOrientationFromBytes(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if (fread($handle, 2) !== "\xFF\xD8") {
                return null;
            }

            while (! feof($handle)) {
                $marker = fread($handle, 2);

                if ($marker === false || strlen($marker) < 2 || $marker[0] !== "\xFF") {
                    return null;
                }

                // Start of scan: pixel data begins, so there is no Exif block.
                if ($marker === "\xFF\xDA") {
                    return null;
                }

                $lengthBytes = fread($handle, 2);

                if ($lengthBytes === false || strlen($lengthBytes) < 2) {
                    return null;
                }

                $length = ((int) unpack('n', $lengthBytes)[1]) - 2;

                if ($length <= 0) {
                    return null;
                }

                if ($marker !== "\xFF\xE1") {
                    fseek($handle, $length, SEEK_CUR);

                    continue;
                }

                $segment = fread($handle, min($length, self::MAX_EXIF_BYTES));

                return $segment === false ? null : $this->orientationFromExifSegment($segment);
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    private function orientationFromExifSegment(string $segment): ?int
    {
        if (! str_starts_with($segment, "Exif\x00\x00")) {
            return null;
        }

        $tiff = substr($segment, 6);

        if (strlen($tiff) < 8) {
            return null;
        }

        $byteOrder = substr($tiff, 0, 2);

        if ($byteOrder === 'II') {
            [$short, $long] = ['v', 'V'];
        } elseif ($byteOrder === 'MM') {
            [$short, $long] = ['n', 'N'];
        } else {
            return null;
        }

        $ifdOffset = (int) unpack($long, substr($tiff, 4, 4))[1];

        if ($ifdOffset < 8 || $ifdOffset + 2 > strlen($tiff)) {
            return null;
        }

        $entryCount = (int) unpack($short, substr($tiff, $ifdOffset, 2))[1];

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $ifdOffset + 2 + ($i * 12);

            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            if ((int) unpack($short, substr($tiff, $entry, 2))[1] !== 0x0112) {
                continue;
            }

            $orientation = (int) unpack($short, substr($tiff, $entry + 8, 2))[1];

            return ($orientation >= 1 && $orientation <= 8) ? $orientation : null;
        }

        return null;
    }
}
