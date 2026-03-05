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

            $mediaLinks = $this->normalizeMediaLinks($mediaUrl);
            $messageBody = $this->buildMessageBody($message, $mediaLinks);
            if ($messageBody !== '') {
                $messageData['body'] = $messageBody;
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
                'body' => $messageBody,
                'media_links_count' => count($mediaLinks),
                'send_mode' => 'sms_with_links',
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

    protected function buildMessageBody($message, array $mediaLinks): string
    {
        $trimmedMessage = trim((string) $message);

        if (empty($mediaLinks)) {
            return $trimmedMessage;
        }

        $linksText = implode("\n", $mediaLinks);

        if ($trimmedMessage === '') {
            return $linksText;
        }

        return $trimmedMessage."\n".$linksText;
    }

    protected function normalizeMediaLinks($mediaUrl): array
    {
        if (empty($mediaUrl)) {
            return [];
        }

        $mediaLinks = is_array($mediaUrl) ? $mediaUrl : [$mediaUrl];
        $normalizedLinks = [];

        foreach ($mediaLinks as $link) {
            $trimmedLink = trim((string) $link);
            if ($trimmedLink !== '') {
                $normalizedLinks[] = $trimmedLink;
            }
        }

        return array_values(array_unique($normalizedLinks));
    }
}
