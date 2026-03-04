<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Twilio\Rest\Api\V2010\Account\MessageInstance;

class TwilioService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.auth_token')
        );
    }

    public function sendMessage($to, $from, $message, $mediaUrl = null): MessageInstance
    {
        try {
            $messageData = [
                'from' => $from,
            ];

            $trimmedMessage = trim((string) $message);
            if ($trimmedMessage !== '') {
                $messageData['body'] = $trimmedMessage;
            }

            if (! empty($mediaUrl)) {
                $messageData['mediaUrl'] = [$mediaUrl];
            }

            $statusCallbackUrl = $this->resolveStatusCallbackUrl();
            if (! empty($statusCallbackUrl)) {
                $messageData['statusCallback'] = $statusCallbackUrl;
            }

            $twilioMessage = $this->client->messages->create($to, $messageData);

            Log::info('Message queued with Twilio', [
                'sid' => $twilioMessage->sid ?? null,
                'status' => $twilioMessage->status ?? null,
                'to' => $to,
                'from' => $from,
                'body' => $trimmedMessage,
                'media' => $mediaUrl ? 'included' : 'none',
            ]);

            return $twilioMessage;
        } catch (\Throwable $e) {
            Log::error('Twilio message send failed', [
                'to' => $to,
                'from' => $from,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function resolveStatusCallbackUrl(): ?string
    {
        $configuredUrl = config('services.twilio.status_callback_url');
        if (! empty($configuredUrl)) {
            return $configuredUrl;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return null;
        }

        return $appUrl.'/api/twilio/status-callback';
    }
}
