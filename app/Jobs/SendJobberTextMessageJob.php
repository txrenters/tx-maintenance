<?php

namespace App\Jobs;

use App\Models\JobberTextMessage;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendJobberTextMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $jobberTextMessage;
    protected $messageContent;
    protected $mediaUrl;

    public $tries = 3;
    public $backoff = [60, 180, 300]; // Retry after 1, 3, and 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(JobberTextMessage $jobberTextMessage, string $messageContent, ?string $mediaUrl = null)
    {
        $this->jobberTextMessage = $jobberTextMessage;
        $this->messageContent = $messageContent;
        $this->mediaUrl = $mediaUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Update status to sending
            $this->jobberTextMessage->update(['status' => 'sending']);

            $twilio = new TwilioService;

            // Send the message via Twilio
            $twilio->sendMessage(
                $this->jobberTextMessage->receiver_number,
                $this->jobberTextMessage->sender_number,
                $this->messageContent,
                $this->mediaUrl
            );

            // Update status to sent
            $this->jobberTextMessage->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info("Message sent successfully to {$this->jobberTextMessage->receiver_number}");

        } catch (\Exception $e) {
            // Log the error
            Log::error("Failed to send message to {$this->jobberTextMessage->receiver_number}: " . $e->getMessage());

            // Update status to failed if this is the last retry
            if ($this->attempts() >= $this->tries) {
                $this->jobberTextMessage->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Update the message status to failed
        $this->jobberTextMessage->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        Log::error("Job failed permanently for message to {$this->jobberTextMessage->receiver_number}: " . $exception->getMessage());
    }
}