<?php 
namespace App\Services;

use App\Models\ConversationMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MediaService
{
    public function downloadAndStore(string $url, string $contentType, int $messageId): ?ConversationMedia
    {
        try {
            $response = Http::timeout(30)->get($url);
            
            if (!$response->successful()) {
                throw new \Exception("Failed to download media (HTTP {$response->status()})");
            }

            $extension = $this->getExtension($contentType);
            $fileName = Str::random(40).'.'.$extension;
            $path = "message_media/{$messageId}/{$fileName}";

            Storage::disk('public')->put($path, $response->body());

            return ConversationMedia::create([
                'message_id' => $messageId,
                'original_url' => $url,
                'local_path' => $path,
                'content_type' => $contentType,
                'file_name' => $fileName
            ]);

        } catch (\Exception $e) {
            Log::error("Media download failed: {$e->getMessage()}");
            return null;
        }
    }

    private function getExtension(string $contentType): string
    {
        return match($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            default => 'bin',
        };
    }
}
?>