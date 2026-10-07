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
    public function __invoke(PortalMessageHistoryRequest $request, int $media, PortalMessageHistory $history): BinaryFileResponse
    {
        $attachment = $history->attachment($request->validated('contact'), $request->validated('party'), $media);
        $disk = collect(['local', 'public'])->first(fn (string $disk): bool => $attachment !== null && Storage::disk($disk)->exists($attachment->local_path));

        abort_if($disk === null, 404);

        return response()->file(Storage::disk($disk)->path($attachment->local_path), [
            'Content-Type' => $attachment->content_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
