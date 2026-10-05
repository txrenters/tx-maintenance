<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ChatbotHub;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncPortalConversationToChatbot implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(public int $conversationId) {}

    /**
     * Execute the job.
     */
    public function handle(ChatbotHub $hub): void
    {
        $conversation = Conversation::withoutGlobalScopes()->with('media')->findOrFail($this->conversationId);
        $hub->send($conversation, (string) $conversation->message, $conversation->media->pluck('public_url')->all());
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Portal message could not sync to chatbot', ['conversation_id' => $this->conversationId, 'error' => $exception?->getMessage()]);
    }
}
