<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class AttachmentOptimizer
{
    /** @var array<int, string> */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Compress image bytes in place (lossless-ish, no resize). Non-images and any
     * failure return the original bytes unchanged — mirrors app/Jobs/UploadAttachment.php.
     */
    public function optimize(string $bytes, string $mime): string
    {
        if (! in_array($mime, self::IMAGE_MIMES, true) || $bytes === '') {
            return $bytes;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'emlimg');

        if ($tmp === false) {
            return $bytes;
        }

        try {
            file_put_contents($tmp, $bytes);
            OptimizerChainFactory::create()->optimize($tmp);
            $optimized = file_get_contents($tmp);

            return $optimized !== false && $optimized !== '' ? $optimized : $bytes;
        } catch (\Throwable $e) {
            Log::warning('Email attachment image optimization failed; using original', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
