<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Services\TenantPhotoMirrorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class ImportChatbotMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /** @param list<string> $urls */
    public function __construct(public int $conversationId, public array $urls) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->conversationId);
        foreach ($this->urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $trusted = [parse_url((string) config('services.chatbot.url'), PHP_URL_HOST), 'api.twilio.com'];
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, array_filter($trusted), true)) {
                throw new \RuntimeException('Chatbot attachment URL is not on a trusted media host.');
            }

            Cache::lock('chatbot-media:'.$conversation->id.':'.sha1($url), 60)->block(5, function () use ($conversation, $url, $host): void {
                if ($conversation->media()->where('original_url', $url)->exists()) {
                    return;
                }
                $request = Http::connectTimeout(5)->timeout(25)->withoutRedirecting();
                if ($host === 'api.twilio.com') {
                    $request = $request->withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.auth_token'))
                        ->withOptions(['allow_redirects' => [
                            'max' => 3,
                            'protocols' => ['https'],
                            'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                                $host = $uri->getHost();
                                if (! str_ends_with($host, '.twilio.com') && ! str_ends_with($host, '.twiliocdn.com') && ! str_ends_with($host, '.amazonaws.com')) {
                                    throw new \RuntimeException('Unexpected Twilio media redirect host.');
                                }
                            },
                        ]]);
                }
                $response = $request->get($url)->throw();
                if (! $response->successful()) {
                    throw new \RuntimeException('Chatbot attachment download did not return a file.');
                }
                $path = 'message_media/'.$conversation->id.'/'.sha1($url);
                Storage::disk('local')->put($path, $response->body());
                $media = ConversationMedia::create([
                    'message_id' => $conversation->id,
                    'original_url' => $url,
                    'local_path' => $path,
                    'content_type' => explode(';', $response->header('Content-Type') ?: 'application/octet-stream')[0],
                    'file_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'attachment',
                ]);
                $conversation->update(['is_mms' => true]);
                if ($conversation->chatbot_direction === 'inbound') {
                    app(TenantPhotoMirrorService::class)->mirrorForConversation($conversation, [$media]);
                }
            });
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Chatbot attachments could not sync', ['conversation_id' => $this->conversationId, 'error' => $exception?->getMessage()]);
    }
}
