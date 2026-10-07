<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalMessageHistoryRequest;
use App\Services\PortalMessageHistory;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A file attached to one of a client's earlier work order texts, for the
 * client portal to pass on to them. Only a file on a text the client may see
 * is served; anything else is not found.
 */
class PortalMessageMediaController extends Controller
{
    /** Types named as themselves; anything else (SVG, HTML) goes out as plain bytes. */
    private const SAFE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf', 'video/mp4', 'video/3gpp', 'video/quicktime', 'audio/amr',
    ];

    public function __invoke(PortalMessageHistoryRequest $request, int $media, PortalMessageHistory $history): BinaryFileResponse
    {
        $attachment = $history->attachment($request->validated('contact'), $request->validated('party'), $media);
        $disk = collect(['local', 'public'])->first(fn (string $disk): bool => $attachment !== null && Storage::disk($disk)->exists($attachment->local_path));

        abort_if($disk === null, 404);

        $type = strtolower((string) $attachment->content_type);
        $safe = in_array($type, self::SAFE_TYPES, true);

        return response()->file(Storage::disk($disk)->path($attachment->local_path), [
            'Content-Type' => $safe ? $type : 'application/octet-stream',
            'Content-Disposition' => $safe ? 'inline' : 'attachment',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
