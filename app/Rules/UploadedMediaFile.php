<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class UploadedMediaFile implements ValidationRule
{
    /**
     * Video extensions accepted even when PHP cannot detect a video mime type.
     * Phone recordings (notably Android .3gp) are often sniffed as
     * application/octet-stream, so the extension is the reliable signal.
     */
    private const VIDEO_EXTENSIONS = [
        '3gp', '3gpp', '3g2', 'mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm',
        'wmv', 'flv', 'mpg', 'mpeg', 'mts', 'm2ts', 'ts', 'hevc', 'ogv',
    ];

    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'heic', 'heif',
    ];

    /**
     * @param  array<int, string>  $documentExtensions  extra non-media extensions to allow (e.g. pdf, docx)
     */
    public function __construct(private array $documentExtensions = []) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute failed to upload.');

            return;
        }

        $mime = (string) $value->getMimeType();
        $extension = strtolower((string) $value->getClientOriginalExtension());

        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            return;
        }

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)
            || in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return;
        }

        if (in_array($extension, $this->documentExtensions, true)) {
            return;
        }

        $allowed = implode(', ', array_merge(['any image', 'any video'], $this->documentExtensions));
        $fail("The :attribute must be one of the following: {$allowed}.");
    }
}
