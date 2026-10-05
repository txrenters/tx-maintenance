<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ChatbotHub;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendConversationMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 180, 300];

    /**
     * @param  string|list<string>|null  $mediaUrl  one media link, or several (a
     *                                              schedule with two technicians
     *                                              sends both photos)
     */
    public function __construct(
        protected string $to,
        protected string $from,
        protected string $message,
        protected string|array|null $mediaUrl = null,
        protected ?int $conversationId = null,
    ) {}

    public function handle(): void
    {
        try {
            $conversation = $this->conversationId
                ? Conversation::withoutGlobalScopes()->findOrFail($this->conversationId)
                : null;

            if ($conversation?->chatbot_thread_id && ! config('services.chatbot.enabled')) {
                throw new \RuntimeException('Chatbot delivery is paused; this linked message cannot fall back to another number.');
            }

            if ($conversation && app(ChatbotHub::class)->handles($conversation)) {
                app(ChatbotHub::class)->send($conversation, $this->message, $this->mediaUrl);

                return;
            }

            $twilio = app(TwilioService::class);
            $twilioMessage = $twilio->sendMessage($this->to, $this->from, $this->message, $this->mediaUrl);

            if ($this->conversationId) {
                Conversation::find($this->conversationId)?->update([
                    'twilio_sid' => $twilioMessage->sid ?? null,
                    'twilio_status' => $twilioMessage->status ?? 'queued',
                    'twilio_status_updated_at' => now(),
                    'twilio_error_code' => null,
                    'twilio_error_message' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendConversationMessageJob failed', [
                'to' => $this->to,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);

            if ($this->conversationId && $this->attempts() >= $this->tries) {
                Conversation::find($this->conversationId)?->update([
                    'twilio_status' => 'failed',
                    'twilio_status_updated_at' => now(),
                    'twilio_error_code' => (string) $e->getCode(),
                    'twilio_error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->conversationId) {
            Conversation::find($this->conversationId)?->update([
                'twilio_status' => 'failed',
                'twilio_status_updated_at' => now(),
                'twilio_error_code' => (string) $exception->getCode(),
                'twilio_error_message' => $exception->getMessage(),
            ]);
        }

        Log::error('SendConversationMessageJob permanently failed', [
            'to' => $this->to,
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
