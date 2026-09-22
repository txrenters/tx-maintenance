<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphMailService
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const TOKEN_CACHE_KEY = 'microsoft.graph.token';

    private string $tenantId;

    private string $clientId;

    private string $clientSecret;

    private string $mailbox;

    public function __construct()
    {
        $this->tenantId = (string) config('services.microsoft.tenant_id');
        $this->clientId = (string) config('services.microsoft.client_id');
        $this->clientSecret = (string) config('services.microsoft.client_secret');
        $this->mailbox = (string) config('services.microsoft.mailbox');
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, array{name: string, contentType: string, contentBytes: string}>  $attachments
     * @param  array<int, string>  $replyTo  Reply-To addresses (e.g. a no-reply mailbox for one-way notifications)
     * @return array{graph_message_id: string, internet_message_id: ?string, graph_conversation_id: ?string}
     */
    public function sendMail(
        string $to,
        array $cc,
        string $subject,
        string $html,
        array $attachments = [],
        ?string $mailbox = null,
        array $replyTo = [],
    ): array {
        $senderMailbox = $mailbox ?? $this->mailbox;
        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
            'ccRecipients' => array_map(
                fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                array_values($cc),
            ),
        ];

        if ($replyTo !== []) {
            $message['replyTo'] = array_map(
                fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                array_values($replyTo),
            );
        }

        if ($attachments !== []) {
            $message['attachments'] = array_map(fn (array $a) => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $a['name'],
                'contentType' => $a['contentType'],
                'contentBytes' => $a['contentBytes'],
            ], $attachments);
        }

        $draft = $this->request()
            ->post("/users/{$senderMailbox}/messages", $message)
            ->throw()
            ->json();

        $id = (string) $draft['id'];

        $this->request()
            ->post("/users/{$senderMailbox}/messages/{$id}/send")
            ->throw();

        return [
            'graph_message_id' => $id,
            'internet_message_id' => $draft['internetMessageId'] ?? null,
            'graph_conversation_id' => $draft['conversationId'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchInbox(CarbonInterface $since, ?string $mailbox = null): array
    {
        $messages = [];
        $senderMailbox = $mailbox ?? $this->mailbox;
        $url = "/users/{$senderMailbox}/mailFolders/inbox/messages";
        $query = [
            '$select' => 'id,subject,from,receivedDateTime,hasAttachments,conversationId,internetMessageId,body,internetMessageHeaders',
            '$filter' => 'receivedDateTime ge '.$since->toIso8601ZuluString(),
            '$orderby' => 'receivedDateTime asc',
            '$top' => 50,
        ];

        do {
            $response = $this->request()->get($url, $query ?: null)->throw()->json();
            $messages = array_merge($messages, $response['value'] ?? []);
            $url = $response['@odata.nextLink'] ?? null;
            $query = []; // nextLink already carries the query string
        } while ($url !== null);

        return $messages;
    }

    /**
     * @return array<int, array{name: string, contentType: string, bytes: string}>
     */
    public function getAttachments(string $messageId, ?string $mailbox = null): array
    {
        $senderMailbox = $mailbox ?? $this->mailbox;
        $response = $this->request()
            ->get("/users/{$senderMailbox}/messages/{$messageId}/attachments")
            ->throw()
            ->json();

        $files = [];
        foreach ($response['value'] ?? [] as $attachment) {
            if (($attachment['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
                continue; // skip item/reference attachments — no file bytes
            }

            $files[] = [
                'name' => $attachment['name'] ?? 'attachment',
                'contentType' => $attachment['contentType'] ?? 'application/octet-stream',
                'bytes' => base64_decode((string) ($attachment['contentBytes'] ?? ''), true) ?: '',
            ];
        }

        return $files;
    }

    /**
     * Reply to a message in place, so the answer lands in the sender's thread
     * and goes out from the mailbox they wrote to. Graph fills in the
     * recipient and the "Re:" subject from the original.
     */
    public function reply(string $messageId, string $html, ?string $mailbox = null): void
    {
        $senderMailbox = $mailbox ?? $this->mailbox;
        $this->request()
            ->post("/users/{$senderMailbox}/messages/{$messageId}/reply", [
                'message' => [
                    'body' => ['contentType' => 'HTML', 'content' => $html],
                ],
            ])
            ->throw();
    }

    public function markRead(string $messageId, ?string $mailbox = null): void
    {
        $senderMailbox = $mailbox ?? $this->mailbox;
        $this->request()
            ->patch("/users/{$senderMailbox}/messages/{$messageId}", ['isRead' => true])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->baseUrl(self::GRAPH_BASE)
            ->acceptJson();
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        )->throw();

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }
}
