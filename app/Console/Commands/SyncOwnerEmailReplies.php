<?php

namespace App\Console\Commands;

use App\Models\OwnerEmailNotification;
use App\Services\AttachmentOptimizer;
use App\Services\HtmlSanitizer;
use App\Services\MicrosoftGraphMailService;
use App\Services\OwnerEmailAttachmentStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOwnerEmailReplies extends Command
{
    protected $signature = 'owner-emails:sync-replies';

    protected $description = 'Poll the workorders mailbox for property-owner email replies';

    private const CURSOR_KEY = 'owner-emails.replies.cursor';

    public function handle(MicrosoftGraphMailService $graph, HtmlSanitizer $sanitizer, AttachmentOptimizer $optimizer, OwnerEmailAttachmentStore $attachmentStore): int
    {
        $mailbox = (string) config('services.microsoft.mailbox');
        $cached = Cache::get(self::CURSOR_KEY);
        $since = is_string($cached) ? Carbon::parse($cached) : now()->subHour();
        $maxReceived = $since->copy();
        $failed = false;

        foreach ($graph->fetchInbox($since, $mailbox) as $message) {
            try {
                $receivedAt = Carbon::parse($message['receivedDateTime'] ?? now());
                $graphId = $message['id'] ?? null;

                if (! is_string($graphId) || $graphId === '' || OwnerEmailNotification::query()->where('graph_message_id', $graphId)->exists()) {
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
                    $inbound = OwnerEmailNotification::query()->create([
                        'work_order_id' => $outbound->work_order_id,
                        'owner_id' => $outbound->owner_id,
                        'vendor_id' => $outbound->vendor_id,
                        'type' => 'vendor_assignment_reply',
                        'direction' => 'inbound',
                        'subject' => $message['subject'] ?? '',
                        'body_html' => $bodyType === 'html' ? $sanitizer->clean($body) : '',
                        'body_text' => $bodyType === 'html' ? trim(strip_tags($body)) : $body,
                        'from_email' => $message['from']['emailAddress']['address'] ?? '',
                        'to_email' => $mailbox,
                        'cc' => [],
                        'correlation_tag' => $outbound->correlation_tag,
                        'graph_message_id' => $graphId,
                        'graph_conversation_id' => $message['conversationId'] ?? null,
                        'internet_message_id' => $message['internetMessageId'] ?? null,
                        'in_reply_to' => $this->replyHeaderIds($message)[0] ?? null,
                        'has_attachments' => $hasAttachments,
                        'sent_at' => $receivedAt,
                    ]);

                    if ($hasAttachments) {
                        foreach ($graph->getAttachments($graphId, $mailbox) as $file) {
                            $attachmentStore->persist($inbound, $file['name'], $file['contentType'], $optimizer->optimize($file['bytes'], $file['contentType']));
                        }
                    }
                });

                $graph->markRead($graphId, $mailbox);
                $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;
            } catch (Throwable $exception) {
                $failed = true;
                Log::warning('owner-emails:sync-replies failed for a message', ['id' => $message['id'] ?? null, 'error' => $exception->getMessage()]);
            }
        }

        if (! $failed) {
            Cache::put(self::CURSOR_KEY, $maxReceived->toIso8601ZuluString());
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $message */
    private function matchOutbound(array $message): ?OwnerEmailNotification
    {
        if (preg_match('/\[(TXO-GEN-\d+)\]/', (string) ($message['subject'] ?? ''), $matches) === 1) {
            return OwnerEmailNotification::query()
                ->where('direction', 'outbound')
                ->where('correlation_tag', $matches[1])
                ->latest('id')
                ->first();
        }

        if (preg_match('/\[TXO-(\d+)-(\d+)\]/', (string) ($message['subject'] ?? ''), $matches) === 1) {
            return OwnerEmailNotification::query()->where('direction', 'outbound')->where('owner_id', (int) $matches[2])->whereHas('workOrder', fn (Builder $query) => $query->where('work_order_no', $matches[1]))->latest('id')->first();
        }

        $ids = $this->replyHeaderIds($message);
        $conversationId = $message['conversationId'] ?? null;
        if ($ids === [] && (! is_string($conversationId) || $conversationId === '')) {
            return null;
        }

        return OwnerEmailNotification::query()->where('direction', 'outbound')->where(function (Builder $query) use ($ids, $conversationId): void {
            if ($ids !== []) {
                $query->orWhereIn('internet_message_id', $ids);
            }
            if (is_string($conversationId) && $conversationId !== '') {
                $query->orWhere('graph_conversation_id', $conversationId);
            }
        })->latest('id')->first();
    }

    /** @param array<string, mixed> $message @return array<int, string> */
    private function replyHeaderIds(array $message): array
    {
        $ids = [];
        foreach (($message['internetMessageHeaders'] ?? []) as $header) {
            if (in_array(strtolower((string) ($header['name'] ?? '')), ['in-reply-to', 'references'], true)) {
                preg_match_all('/<[^>]+>/', (string) ($header['value'] ?? ''), $matches);
                $ids = array_merge($ids, $matches[0]);
            }
        }

        return array_values(array_unique($ids));
    }
}
