<?php

namespace App\Services\Images;

use App\Models\WorkOrderDocuments;
use Illuminate\Support\Facades\Log;

/**
 * Rotation for PropertyWare-synced documents. Their bytes live in PropertyWare
 * and are fetched on every view, so instead of rewriting a file the turn is
 * remembered on the row (`rotation`, clockwise degrees) and applied to the
 * bytes each time the document is streamed. PropertyWare's copy is untouched.
 */
class WorkOrderDocumentRotator
{
    public function __construct(private ImageProcessor $processor) {}

    /**
     * Only image documents GD can decode carry a rotation.
     */
    public function canRotate(WorkOrderDocuments $document): bool
    {
        return $this->processor->supports($this->mimeFor($document));
    }

    /**
     * Remember a quarter turn in the given direction. Returns the new angle.
     */
    public function turn(WorkOrderDocuments $document, string $direction): int
    {
        $step = match ($direction) {
            AttachmentRotator::CLOCKWISE => 90,
            AttachmentRotator::COUNTERCLOCKWISE => 270,
            default => 0,
        };

        $rotation = ((int) $document->rotation + $step) % 360;

        $document->forceFill(['rotation' => $rotation])->save();

        return $rotation;
    }

    /**
     * Apply the remembered turn to freshly fetched bytes. Anything that cannot
     * be rotated (no turn, non-image, undecodable) comes back as it arrived, so
     * a bad file is still served rather than lost.
     */
    public function apply(string $bytes, int $rotation): string
    {
        if ($rotation === 0 || $bytes === '') {
            return $bytes;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wodrot');

        if ($tmp === false) {
            return $bytes;
        }

        try {
            file_put_contents($tmp, $bytes);

            return $this->processor->rotate($tmp, $rotation) ?? $bytes;
        } catch (\Throwable $e) {
            Log::warning('Document rotation failed; serving the original', [
                'rotation' => $rotation,
                'error' => $e->getMessage(),
            ]);

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * PropertyWare often reports octet-stream, so fall back to the extension
     * the same way the download endpoint does.
     */
    private function mimeFor(WorkOrderDocuments $document): string
    {
        $reported = strtolower(trim(explode(';', (string) $document->file_type)[0]));

        if (str_starts_with($reported, 'image/')) {
            return $reported;
        }

        return match (strtolower(pathinfo((string) $document->file_name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => '',
        };
    }
}
