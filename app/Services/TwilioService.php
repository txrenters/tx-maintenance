<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Client;

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

    /**
     * Fetch a single Twilio message by SID. Returns null when the message cannot be found.
     *
     * @return array<string, mixed>|null
     */
    public function fetchMessage(string $sid): ?array
    {
        $sid = trim($sid);
        if ($sid === '') {
            return null;
        }

        try {
            $message = $this->client->messages($sid)->fetch();

            return $this->mapMessage($message);
        } catch (RestException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            Log::error('Twilio message fetch failed', [
                'sid' => $sid,
                'status_code' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Search Twilio messages with optional phone and date filters.
     *
     * @param  array{phone?: ?string, date_from?: ?\DateTimeInterface, date_to?: ?\DateTimeInterface, limit?: ?int}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchMessages(array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 100);
        $limit = max(1, min($limit, 500));

        $phone = isset($filters['phone']) ? trim((string) $filters['phone']) : '';
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $baseOptions = [];
        if ($dateFrom instanceof \DateTimeInterface) {
            $baseOptions['dateSentAfter'] = $dateFrom;
        }
        if ($dateTo instanceof \DateTimeInterface) {
            $baseOptions['dateSentBefore'] = $dateTo;
        }

        if ($phone === '') {
            return $this->collectMessages($baseOptions, $limit);
        }

        // Twilio's API filters by exact From or To, so we query both sides and merge.
        $fromMessages = $this->collectMessages(array_merge($baseOptions, ['from' => $phone]), $limit);
        $toMessages = $this->collectMessages(array_merge($baseOptions, ['to' => $phone]), $limit);

        $merged = [];
        foreach (array_merge($fromMessages, $toMessages) as $message) {
            $sid = $message['sid'] ?? null;
            if ($sid === null) {
                continue;
            }
            $merged[$sid] = $message;
        }

        usort($merged, function (array $a, array $b): int {
            return strcmp((string) ($b['date_sent'] ?? ''), (string) ($a['date_sent'] ?? ''));
        });

        return array_slice(array_values($merged), 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    protected function collectMessages(array $options, int $limit): array
    {
        try {
            $messages = $this->client->messages->read($options, $limit);
        } catch (RestException $e) {
            Log::error('Twilio message search failed', [
                'options' => array_keys($options),
                'status_code' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return array_map(fn (MessageInstance $message): array => $this->mapMessage($message), $messages);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapMessage(MessageInstance $message): array
    {
        $dateSent = $message->dateSent instanceof \DateTimeInterface
            ? $message->dateSent->format(\DateTimeInterface::ATOM)
            : null;
        $dateCreated = $message->dateCreated instanceof \DateTimeInterface
            ? $message->dateCreated->format(\DateTimeInterface::ATOM)
            : null;
        $dateUpdated = $message->dateUpdated instanceof \DateTimeInterface
            ? $message->dateUpdated->format(\DateTimeInterface::ATOM)
            : null;

        return [
            'sid' => $message->sid,
            'account_sid' => $message->accountSid,
            'from' => $message->from,
            'to' => $message->to,
            'body' => $message->body,
            'status' => $message->status,
            'direction' => $message->direction,
            'error_code' => $message->errorCode !== null ? (string) $message->errorCode : null,
            'error_message' => $message->errorMessage,
            'price' => $message->price,
            'price_unit' => $message->priceUnit,
            'num_segments' => $message->numSegments,
            'num_media' => $message->numMedia,
            'date_sent' => $dateSent,
            'date_created' => $dateCreated,
            'date_updated' => $dateUpdated,
            'messaging_service_sid' => $message->messagingServiceSid,
            'uri' => $message->uri,
        ];
    }

    /**
     * Stream inbound Twilio messages addressed to a given number since a given date.
     *
     * Yields each Twilio MessageInstance so callers can inspect direction, media, etc.
     *
     * @return iterable<MessageInstance>
     */
    public function streamInboundMessagesTo(string $toNumber, \DateTimeInterface $dateSentAfter, int $limit = 200): iterable
    {
        $options = [
            'to' => $toNumber,
            'dateSentAfter' => $dateSentAfter,
        ];

        try {
            return $this->client->messages->read($options, max(1, $limit));
        } catch (RestException $e) {
            Log::error('Twilio inbound stream failed', [
                'to' => $toNumber,
                'status_code' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the webhook-style media URL list for a given message SID. Returns
     * an array of ['url' => string, 'content_type' => string].
     *
     * @return array<int, array{url: string, content_type: string}>
     */
    public function fetchMessageMedia(string $sid): array
    {
        $sid = trim($sid);
        if ($sid === '') {
            return [];
        }

        try {
            $mediaList = $this->client->messages($sid)->media->read();
        } catch (RestException $e) {
            if ($e->getStatusCode() === 404) {
                return [];
            }

            Log::error('Twilio media fetch failed', [
                'sid' => $sid,
                'status_code' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $accountSid = (string) config('services.twilio.sid');

        $items = [];
        foreach ($mediaList as $media) {
            $mediaSid = $media->sid;
            $contentType = (string) ($media->contentType ?? '');
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages/{$sid}/Media/{$mediaSid}";

            $items[] = ['url' => $url, 'content_type' => $contentType];
        }

        return $items;
    }

    /**
     * May this deployment text this number?
     *
     * Public because it is a policy question a caller can ask before doing work it would
     * only have to discard. Checked in order: SMS_ENABLED=false stops everything, then
     * SMS_ALLOWLIST (when set) narrows sending to exactly those numbers.
     *
     * This sits ABOVE the per-feature flags in config/services.php. Those choose which
     * texts a working deployment sends; this decides whether it may text anyone at all.
     */
    public function shouldSend(string $to): bool
    {
        if (! config('services.twilio.outbound_enabled', true)) {
            return false;
        }

        $allowlist = collect(explode(',', (string) config('services.twilio.allowlist', '')))
            ->map(fn (string $number): string => $this->normalizeNumber($number))
            ->filter();

        if ($allowlist->isEmpty()) {
            return true;
        }

        return $allowlist->contains($this->normalizeNumber($to));
    }

    /**
     * Last ten digits, so +1 (555) 999-8888 and 5559998888 compare equal. An allowlist
     * that fails on formatting silently blocks the one number you meant to test with.
     */
    private function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/[^0-9]/', '', trim($number)) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public function sendMessage($to, $from, $message, $mediaUrl = null): ?MessageInstance
    {
        if (! $this->shouldSend((string) $to)) {
            Log::warning('Outbound SMS suppressed before reaching Twilio', [
                'to' => $to,
                'from' => $from,
                'body' => is_string($message) ? $message : null,
                'reason' => config('services.twilio.outbound_enabled', true)
                    ? 'not on SMS_ALLOWLIST'
                    : 'SMS_ENABLED is false',
            ]);

            // Null, not an exception: the callers read the result with ?->sid and fall back
            // to a "queued" status, so the surrounding flow still runs end to end.
            return null;
        }

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
