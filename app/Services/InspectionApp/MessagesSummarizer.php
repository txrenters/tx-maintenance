<?php

namespace App\Services\InspectionApp;

use App\Ai\Agents\WorkOrderMessagesSummaryAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\OwnerEmailNotification;
use App\Models\TenantEmailNotification;
use App\Models\WorkOrder;
use App\Services\AiSettings;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A short AI summary of a work order's messages for the inspection app's work
 * order dialog (the office, 2026-11-21: "summary for messages from
 * maintenance db").
 *
 * Built the way OwnerApprovalReader is: one summary per work order, stored as
 * an AiInsight whose subject is the WorkOrder row, with a fingerprint of the
 * messages it read. A work order whose messages have not changed is never
 * summarized again; a new message makes it stale, and the next send writes it
 * again. A failed call stores nothing and sends without a summary.
 */
class MessagesSummarizer
{
    /** The newest messages read; an old thread's start rarely matters to the crew. */
    private const MAX_MESSAGES = 60;

    private const MAX_MESSAGE = 800;

    public function enabled(): bool
    {
        return (bool) config('services.inspection_app.handoff_messages_summary', true) && AiSettings::ready();
    }

    /**
     * What the summary was read from, cheaply: how many messages of each kind
     * and the newest of each. A new text or email changes it.
     */
    public function fingerprint(WorkOrder $workOrder): string
    {
        $parts = [];

        foreach ([
            Conversation::query()->withoutGlobalScopes(),
            OwnerEmailNotification::query(),
            TenantEmailNotification::query(),
        ] as $query) {
            $row = $query->where('work_order_id', $workOrder->id)->selectRaw('COUNT(*) as total, MAX(id) as newest')->first();
            $parts[] = ($row?->total ?? 0).':'.($row?->newest ?? 0);
        }

        return implode('|', $parts);
    }

    public function latestFor(WorkOrder $workOrder): ?AiInsight
    {
        return AiInsight::query()
            ->ofType(AiInsight::TYPE_MESSAGES_SUMMARY)
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $workOrder->id)
            ->first();
    }

    public function isStale(WorkOrder $workOrder, ?AiInsight $insight = null): bool
    {
        $insight ??= $this->latestFor($workOrder);

        return $insight === null || (string) data_get($insight->data, 'fingerprint') !== $this->fingerprint($workOrder);
    }

    /**
     * Writes the summary again when the messages changed since the last one.
     * A work order with no messages gets none, and costs no AI call.
     */
    public function refresh(WorkOrder $workOrder): ?AiInsight
    {
        $insight = $this->latestFor($workOrder);

        if (! $this->enabled() || ! $this->isStale($workOrder, $insight)) {
            return $insight;
        }

        $messages = $this->messages($workOrder);

        if ($messages->isEmpty()) {
            return $insight;
        }

        $summary = $this->ask($workOrder, $this->text($workOrder, $messages));

        if ($summary === null) {
            return $insight;
        }

        return AiInsight::query()->updateOrCreate(
            [
                'type' => AiInsight::TYPE_MESSAGES_SUMMARY,
                'subject_type' => WorkOrder::class,
                'subject_id' => $workOrder->id,
            ],
            [
                'work_order_id' => $workOrder->id,
                'status' => AiInsight::STATUS_OPEN,
                'data' => [
                    'summary' => $summary,
                    'messages_read' => $messages->count(),
                    'fingerprint' => $this->fingerprint($workOrder),
                    'model' => AiSettings::effectiveModel(),
                ],
                'generated_at' => now(),
            ],
        );
    }

    /**
     * What the hand-off carries: the stored summary, or null when there is none.
     *
     * @return array{summary: string, messages_read: int, read_at: ?string}|null
     */
    public function forPayload(WorkOrder $workOrder): ?array
    {
        $insight = $this->latestFor($workOrder);
        $summary = trim((string) data_get($insight?->data, 'summary'));

        if ($insight === null || $summary === '') {
            return null;
        }

        return [
            'summary' => Str::limit($summary, 1990),
            'messages_read' => (int) data_get($insight->data, 'messages_read', 0),
            'read_at' => $insight->generated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, array{who: string, when: string, channel: string, text: string}>  $messages
     */
    public function text(WorkOrder $workOrder, Collection $messages): string
    {
        $lines = [
            'WORK ORDER #'.($workOrder->work_order_no ?? $workOrder->id),
            'Category: '.trim((string) $workOrder->category).' / '.trim((string) $workOrder->type),
            'Description: '.$this->excerpt($workOrder->description, 2000),
            '',
            'MESSAGES (oldest first)',
        ];

        foreach ($messages as $message) {
            $lines[] = sprintf('    %s (%s, %s): "%s"', $message['who'], $message['when'], $message['channel'], $message['text']);
        }

        return implode("\n", $lines);
    }

    /**
     * Every text and email on the work order, newest MAX_MESSAGES, oldest
     * first. `is_read` is the direction on a text: true is the office writing.
     *
     * @return Collection<int, array{who: string, when: string, channel: string, text: string, at: string}>
     */
    public function messages(WorkOrder $workOrder): Collection
    {
        $texts = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get(['id', 'message', 'is_read', 'conversation_type', 'created_at'])
            ->map(fn (Conversation $message): array => [
                'who' => $message->is_read ? 'office to '.$this->party($message->conversation_type) : $this->party($message->conversation_type),
                'when' => $this->when($message->created_at),
                'channel' => 'SMS',
                'text' => $this->excerpt($message->message, self::MAX_MESSAGE),
                'at' => $this->sortKey($message->created_at),
            ]);

        $emails = collect([
            'owner' => OwnerEmailNotification::query(),
            'tenant' => TenantEmailNotification::query(),
        ])->flatMap(fn ($query, string $party) => $query
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get(['id', 'direction', 'body_text', 'created_at'])
            ->map(fn ($email): array => [
                'who' => $email->direction === 'inbound' ? $party : 'office to '.$party,
                'when' => $this->when($email->created_at),
                'channel' => 'email',
                'text' => $this->excerpt($email->body_text, self::MAX_MESSAGE),
                'at' => $this->sortKey($email->created_at),
            ]));

        return $texts->concat($emails)
            ->filter(fn (array $message): bool => $message['text'] !== '')
            ->sortBy('at')
            ->slice(-self::MAX_MESSAGES)
            ->values();
    }

    private function ask(WorkOrder $workOrder, string $text): ?string
    {
        try {
            $response = (new WorkOrderMessagesSummaryAgent)->prompt($text, timeout: 45);
        } catch (\Throwable $exception) {
            Log::warning('Work order messages summary failed; it will be written on the next send.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $answer = match (true) {
            is_array($response) => $response,
            $response instanceof Arrayable => $response->toArray(),
            default => (array) $response,
        };
        $summary = trim((string) ($answer['summary'] ?? ''));

        return $summary === '' ? null : $summary;
    }

    private function party(?string $type): string
    {
        return match ($type) {
            'owner' => 'owner',
            'tenant' => 'tenant',
            'vendor' => 'vendor',
            'vendor_tenant' => 'vendor-tenant thread',
            'vendor_owner' => 'vendor-owner thread',
            default => 'contact',
        };
    }

    private function sortKey(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function when(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function excerpt(?string $value, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $value)), $limit);
    }
}
