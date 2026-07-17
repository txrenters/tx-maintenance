<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AttachmentOptimizer;
use App\Services\EmailAttachmentStore;
use App\Services\HtmlSanitizer;
use App\Services\MicrosoftGraphMailService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncEmailReplies extends Command
{
    protected $signature = 'emails:sync-replies';

    protected $description = 'Poll the workorders mailbox for vendor email replies and thread them onto work orders';

    private const CURSOR_KEY = 'emails.replies.cursor';

    public function handle(
        MicrosoftGraphMailService $graph,
        HtmlSanitizer $sanitizer,
        AttachmentOptimizer $optimizer,
        EmailAttachmentStore $attachmentStore,
    ): int {
        $cachedCursor = Cache::get(self::CURSOR_KEY);
        $since = is_string($cachedCursor) ? Carbon::parse($cachedCursor) : now()->subHour();
        $maxReceived = $since->copy();

        foreach ($graph->fetchInbox($since) as $message) {
            try {
                $receivedAt = Carbon::parse($message['receivedDateTime'] ?? now());
                $graphMessageId = $message['id'] ?? null;

                if (! is_string($graphMessageId) || $graphMessageId === '' || EmailMessage::query()->where('graph_message_id', $graphMessageId)->exists()) {
                    $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;

                    continue;
                }

                $match = $this->matchMessage($message);

                if ($match === null) {
                    $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;

                    continue;
                }

                $outbound = $match;
                $bodyType = strtolower((string) ($message['body']['contentType'] ?? 'html'));
                $bodyContent = (string) ($message['body']['content'] ?? '');
                $hasAttachments = (bool) ($message['hasAttachments'] ?? false);

                DB::transaction(function () use ($attachmentStore, $bodyContent, $bodyType, $graph, $graphMessageId, $hasAttachments, $message, $optimizer, $outbound, $receivedAt, $sanitizer): void {
                    $inbound = EmailMessage::query()->create([
                        'work_order_id' => $outbound->work_order_id,
                        'vendor_id' => $outbound->vendor_id,
                        'direction' => 'inbound',
                        'subject' => $message['subject'] ?? '',
                        'body_html' => $bodyType === 'html' ? $sanitizer->clean($bodyContent) : null,
                        'body_text' => $bodyType === 'html' ? trim(strip_tags($bodyContent)) : $bodyContent,
                        'from_email' => $message['from']['emailAddress']['address'] ?? null,
                        'to_email' => config('services.microsoft.mailbox'),
                        'correlation_tag' => $outbound->correlation_tag,
                        'graph_message_id' => $graphMessageId,
                        'graph_conversation_id' => $message['conversationId'] ?? null,
                        'internet_message_id' => $message['internetMessageId'] ?? null,
                        'in_reply_to' => $this->replyHeaderIds($message)[0] ?? null,
                        'has_attachments' => $hasAttachments,
                        'emailed_at' => $receivedAt,
                    ]);

                    if ($hasAttachments) {
                        foreach ($graph->getAttachments($graphMessageId) as $file) {
                            $bytes = $optimizer->optimize($file['bytes'], $file['contentType']);
                            $attachmentStore->persist($inbound, $file['name'], $file['contentType'], $bytes);
                        }
                    }
                });

                try {
                    $graph->markRead($graphMessageId);
                } catch (Throwable $exception) {
                    Log::notice('Unable to mark synced email as read', [
                        'id' => $graphMessageId,
                        'error' => $exception->getMessage(),
                    ]);
                }
                $maxReceived = $receivedAt->greaterThan($maxReceived) ? $receivedAt : $maxReceived;
            } catch (Throwable $exception) {
                Log::warning('emails:sync-replies failed for a message', [
                    'id' => $message['id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);

                break;
            }
        }

        Cache::put(self::CURSOR_KEY, $maxReceived->toIso8601ZuluString());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function matchMessage(array $message): ?EmailMessage
    {
        $subject = (string) ($message['subject'] ?? '');

        if (preg_match('/\[(TX-(?:\d+|GEN)-\d+)\]/', $subject, $matches) === 1) {
            $tagged = EmailMessage::query()
                ->where('direction', 'outbound')
                ->where('correlation_tag', $matches[1])
                ->latest('id')
                ->first();

            if ($tagged !== null) {
                return $tagged;
            }

            if (preg_match('/^TX-(\d+)-(\d+)$/', $matches[1], $parts) === 1) {
                $workOrder = WorkOrder::query()->where('work_order_no', $parts[1])->first();
                $vendor = Vendor::query()->find((int) $parts[2]);

                if ($workOrder !== null && $vendor !== null && $workOrder->vendors()->whereKey($vendor->id)->exists()) {
                    return new EmailMessage([
                        'work_order_id' => $workOrder->id,
                        'vendor_id' => $vendor->id,
                        'correlation_tag' => $matches[1],
                    ]);
                }
            }
        }

        $headerIds = $this->replyHeaderIds($message);
        $conversationId = $message['conversationId'] ?? null;

        if ($headerIds === [] && ! is_string($conversationId)) {
            return null;
        }

        $outbound = EmailMessage::query()
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

        return $outbound?->vendor_id !== null ? $outbound : null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, string>
     */
    private function replyHeaderIds(array $message): array
    {
        $ids = [];

        foreach (($message['internetMessageHeaders'] ?? []) as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));

            if (! in_array($name, ['in-reply-to', 'references'], true)) {
                continue;
            }

            preg_match_all('/<[^>]+>/', (string) ($header['value'] ?? ''), $matches);
            $ids = array_merge($ids, $matches[0]);
        }

        return array_values(array_unique($ids));
    }
}
