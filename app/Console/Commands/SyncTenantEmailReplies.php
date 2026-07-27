<?php

namespace App\Console\Commands;

use App\Models\TenantEmailNotification;
use App\Services\AttachmentOptimizer;
use App\Services\HtmlSanitizer;
use App\Services\MicrosoftGraphMailService;
use App\Services\TenantEmailAttachmentStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTenantEmailReplies extends Command
{
    protected $signature = 'tenant-emails:sync-replies';

    protected $description = 'Poll the tenant reminder mailbox for replies and thread them onto tenants and Jobber jobs';

    private const CURSOR_KEY = 'tenant-emails.replies.cursor';

    public function handle(MicrosoftGraphMailService $graph, HtmlSanitizer $sanitizer, AttachmentOptimizer $optimizer, TenantEmailAttachmentStore $attachmentStore): int
    {
        $mailbox = (string) config('services.microsoft.job_reminder_mailbox');
        $cachedCursor = Cache::get(self::CURSOR_KEY);
        $since = is_string($cachedCursor) ? Carbon::parse($cachedCursor) : now()->subHour();
        $maxReceived = $since->copy();
        $failed = false;

        foreach ($graph->fetchInbox($since, $mailbox) as $message) {
            try {
                $receivedAt = Carbon::parse($message['receivedDateTime'] ?? now());
                $graphId = $message['id'] ?? null;

                if (! is_string($graphId) || $graphId === '' || TenantEmailNotification::query()->where('graph_message_id', $graphId)->exists()) {
                    $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;

                    continue;
                }

                $outbound = $this->matchOutbound($message);

                if ($outbound === null) {
                    $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;

                    continue;
                }

                DB::transaction(function () use ($message, $outbound, $graph, $sanitizer, $optimizer, $attachmentStore, $mailbox, $graphId, $receivedAt): void {
                    $bodyType = strtolower((string) ($message['body']['contentType'] ?? 'html'));
                    $body = (string) ($message['body']['content'] ?? '');
                    $hasAttachments = (bool) ($message['hasAttachments'] ?? false);
                    $inbound = TenantEmailNotification::query()->create([
                        'tenant_id' => $outbound->tenant_id,
                        'jobber_job_id' => $outbound->jobber_job_id,
                        'type' => 'job_reminder_reply',
                        'direction' => 'inbound',
                        'subject' => $message['subject'] ?? '',
                        'body_html' => $bodyType === 'html' ? $sanitizer->clean($body) : '',
                        'body_text' => $bodyType === 'html' ? trim(strip_tags($body)) : $body,
                        'from_email' => $message['from']['emailAddress']['address'] ?? '',
                        'to_email' => $mailbox,
                        'correlation_tag' => $outbound->correlation_tag,
                        'graph_message_id' => $graphId,
                        'graph_conversation_id' => $message['conversationId'] ?? null,
                        'internet_message_id' => $message['internetMessageId'] ?? null,
                        'in_reply_to' => $this->replyHeaderIds($message)[0] ?? null,
                        'has_attachments' => $hasAttachments,
                        'metadata' => ['source' => 'microsoft_graph'],
                        'sent_at' => $receivedAt,
                    ]);

                    if ($hasAttachments) {
                        foreach ($graph->getAttachments($graphId, $mailbox) as $file) {
                            $bytes = $optimizer->optimize($file['bytes'], $file['contentType']);
                            $attachmentStore->persist($inbound, $file['name'], $file['contentType'], $bytes);
                        }
                    }
                });

                $graph->markRead($graphId, $mailbox);
                $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;
            } catch (Throwable $exception) {
                $failed = true;
                Log::warning('tenant-emails:sync-replies failed for a message', ['id' => $message['id'] ?? null, 'error' => $exception->getMessage()]);
            }
        }

        if (! $failed) {
            Cache::put(self::CURSOR_KEY, $maxReceived->toIso8601ZuluString());
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $message */
    private function matchOutbound(array $message): ?TenantEmailNotification
    {
        $subject = (string) ($message['subject'] ?? '');

        if (preg_match('/\[TBP-(\d+)-(\d+)\]/', $subject, $matches) === 1) {
            return TenantEmailNotification::query()
                ->where('direction', 'outbound')
                ->where('jobber_job_id', (int) $matches[1])
                ->where('tenant_id', (int) $matches[2])
                ->latest('id')
                ->first();
        }

        $headerIds = $this->replyHeaderIds($message);
        $conversationId = $message['conversationId'] ?? null;

        if ($headerIds === [] && (! is_string($conversationId) || $conversationId === '')) {
            return null;
        }

        return TenantEmailNotification::query()
            ->where('direction', 'outbound')
            ->where(function (Builder $query) use ($headerIds, $conversationId): void {
                if ($headerIds !== []) {
                    $query->orWhereIn('internet_message_id', $headerIds);
                }
                if (is_string($conversationId) && $conversationId !== '') {
                    $query->orWhere('graph_conversation_id', $conversationId);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, string>
     */
    private function replyHeaderIds(array $message): array
    {
        $ids = [];
        foreach (($message['internetMessageHeaders'] ?? []) as $header) {
            if (! in_array(strtolower((string) ($header['name'] ?? '')), ['in-reply-to', 'references'], true)) {
                continue;
            }
            preg_match_all('/<[^>]+>/', (string) ($header['value'] ?? ''), $matches);
            $ids = array_merge($ids, $matches[0]);
        }

        return array_values(array_unique($ids));
    }
}
