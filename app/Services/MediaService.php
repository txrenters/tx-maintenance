<?php

namespace App\Services;

use App\Models\ConversationMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function downloadAndStore(string $url, string $contentType, int $messageId): ?ConversationMedia
    {
        try {
            $request = Http::timeout(30);

            if (str_contains($url, 'api.twilio.com')) {
                $accountSid = (string) config('services.twilio.sid');
                $authToken = (string) config('services.twilio.auth_token');
                if ($accountSid !== '' && $authToken !== '') {
                    $request = $request->withBasicAuth($accountSid, $authToken);
                }
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                throw new \Exception("Failed to download media (HTTP {$response->status()})");
            }

            $extension = $this->getExtension($contentType);
            $fileName = Str::random(40).'.'.$extension;
            $path = "message_media/{$messageId}/{$fileName}";

            Storage::disk('local')->put($path, $response->body());

            return ConversationMedia::create([
                'message_id' => $messageId,
                'original_url' => $url,
                'local_path' => $path,
                'content_type' => $contentType,
                'file_name' => $fileName,
            ]);

        } catch (\Exception $e) {
            Log::error("Media download failed: {$e->getMessage()}");

            return null;
        }
    }

    private function getExtension(string $contentType): string
    {
        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            default => 'bin',
        };
    }
}
