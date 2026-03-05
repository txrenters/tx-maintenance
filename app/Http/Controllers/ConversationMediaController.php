<?php

namespace App\Http\Controllers;

use App\Models\ConversationMedia;
use Illuminate\Support\Facades\Storage;

class ConversationMediaController extends Controller
{
    public function show(ConversationMedia $media)
    {
        $disk = null;
        if (Storage::disk('local')->exists($media->local_path)) {
            $disk = 'local';
        } elseif (Storage::disk('public')->exists($media->local_path)) {
            // Backward compatibility for files saved before private storage switch.
            $disk = 'public';
        }

        if ($disk === null) {
            abort(404);
        }

        return response()->file(Storage::disk($disk)->path($media->local_path), [
            'Content-Type' => $media->content_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
