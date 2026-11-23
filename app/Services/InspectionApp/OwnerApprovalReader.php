<?php

namespace App\Services\InspectionApp;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\AiSettings;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The AI's reading of a held work order: which of its problems the owner
 * approved, with the owner's words quoted (docs/superpowers/specs/
 * 2026-11-19-work-order-handoff-approval-filter-design.md).
 *
 * Built the way TenantEasyFixReplyJudge is. One reading per work order,
 * stored as an AiInsight whose subject is the WorkOrder row, so a re-run
 * updates in place; the hash of the text it read is kept with it, so the
 * same text is never sent twice and a new message makes the reading stale.
 * A malformed answer, or an approval without the owner's quoted words, is
 * stored as an invalid reading: it never approves, and it never guesses.
 */
class OwnerApprovalReader
{
    public const LOG_NAME = 'owner_approval_reading';

    public const DECISIONS = ['approved', 'declined', 'not_mentioned'];

    private const MAX_MESSAGE = 1200;

    private const MAX_NOTE = 800;

    public function enabled(): bool
    {
        return (bool) config('services.inspection_app.handoff_ai_reader', true) && AiSettings::ready();
    }

    public function minimumConfidence(): int
    {
        return (int) config('services.inspection_app.handoff_ai_confidence', 80);
    }

    /**
     * Everything the AI reads, in the order the spec lists: the work order,
     * the approval fields, the Notes tab, then the owner's messages oldest
     * first. Only owner-party text: tenant and vendor threads are never here.
     */
    public function text(WorkOrder $workOrder, bool $withApprovalFields = true): string
    {
        $estimate = app(ApprovalGate::class)->estimate($workOrder);
        $lines = [
            'WORK ORDER #'.($workOrder->work_order_no ?? $workOrder->id),
            'Estimate: '.($estimate === null ? 'none' : '$'.number_format($estimate, 2)),
            'Category: '.trim((string) $workOrder->category).' / '.trim((string) $workOrder->type),
            'Description: '.$this->excerpt($workOrder->description, 4000),
            'Specific location: '.($this->excerpt($workOrder->specific_location, 300) ?: 'n/a'),
            '',
            'APPROVAL FIELDS (from PropertyWare)',
            // The evaluation hides the two fields it scores the reading
            // against; a held work order in production always has them blank.
            'Approved by: '.($withApprovalFields ? ($this->excerpt($workOrder->approved_by, 120) ?: 'none') : '(hidden for evaluation)'),
            'Approved date: '.($withApprovalFields ? ($workOrder->approved_date ? $this->when($workOrder->approved_date, false) : 'none') : '(hidden for evaluation)'),
            'Approval comment: '.($this->excerpt($workOrder->approval_comments, 2000) ?: 'none'),
            '',
            'NOTES TAB (oldest first)',
        ];

        $notes = $this->notes($workOrder);
        if ($notes->isEmpty()) {
            $lines[] = '    (no notes)';
        }
        foreach ($notes as $note) {
            $lines[] = sprintf('    %s%s: "%s"', $note['when'], $note['subject'] !== '' ? ' '.$note['subject'] : '', $note['body']);
        }

        $lines[] = '';
        $lines[] = 'OWNER THREAD (oldest first; "office" = the property manager, "owner" = the property owner)';
        $messages = $this->ownerMessages($workOrder);
        if ($messages->isEmpty()) {
            $lines[] = '    (no messages with the owner)';
        }
        foreach ($messages as $message) {
            $lines[] = sprintf('    %s (%s, %s): "%s"', $message['who'], $message['when'], $message['channel'], $message['text']);
        }

        return implode("\n", $lines);
    }

    public function hash(WorkOrder $workOrder): string
    {
        return hash('sha256', $this->text($workOrder));
    }

    public function read(WorkOrder $workOrder): ?AiInsight
    {
        $text = $this->text($workOrder);
        $reading = $this->ask($workOrder, $text);

        if ($reading === null) {
            return null;
        }

        $insight = AiInsight::query()->updateOrCreate(
            [
                'type' => AiInsight::TYPE_OWNER_APPROVAL,
                'subject_type' => WorkOrder::class,
                'subject_id' => $workOrder->id,
            ],
            [
                'work_order_id' => $workOrder->id,
                'status' => AiInsight::STATUS_OPEN,
                'confidence' => $reading['confidence'],
                'data' => $reading + [
                    'text_hash' => hash('sha256', $text),
                    'messages_read' => $this->ownerMessages($workOrder)->count(),
                    'model' => AiSettings::effectiveModel(),
                ],
                'generated_at' => now(),
            ],
        );

        $this->record($workOrder, $insight);

        return $insight;
    }

    /**
     * The reading alone, stored nowhere: for the evaluation command.
     *
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, error: ?string}|null
     */
    public function preview(WorkOrder $workOrder): ?array
    {
        return $this->ask($workOrder, $this->text($workOrder, withApprovalFields: false));
    }

    /**
     * Whether the owner has written anything on this work order. An approval
     * stands only on the owner's own words, so without one a reading could
     * never approve and is not worth asking for.
     */
    public function ownerHasWritten(WorkOrder $workOrder): bool
    {
        return $this->ownerMessages($workOrder)->contains(fn (array $message): bool => $message['who'] === 'owner');
    }

    public function latestFor(WorkOrder $workOrder): ?AiInsight
    {
        return AiInsight::query()
            ->ofType(AiInsight::TYPE_OWNER_APPROVAL)
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $workOrder->id)
            ->first();
    }

    public function isStale(WorkOrder $workOrder, AiInsight $insight): bool
    {
        return (string) data_get($insight->data, 'text_hash') !== $this->hash($workOrder);
    }

    public function approves(AiInsight $insight): bool
    {
        return (bool) data_get($insight->data, 'valid', false)
            && (bool) data_get($insight->data, 'approved', false)
            && (int) $insight->confidence >= $this->minimumConfidence();
    }

    /**
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, read_at: ?string, messages_read: int}
     */
    public static function summarize(AiInsight $insight): array
    {
        return [
            'approved' => (bool) data_get($insight->data, 'approved', false),
            'valid' => (bool) data_get($insight->data, 'valid', false),
            'confidence' => (int) $insight->confidence,
            'summary' => (string) data_get($insight->data, 'summary', ''),
            'problems' => array_values((array) data_get($insight->data, 'problems', [])),
            'read_at' => $insight->generated_at?->toJSON(),
            'messages_read' => (int) data_get($insight->data, 'messages_read', 0),
        ];
    }

    public static function describe(AiInsight $insight): string
    {
        $reading = self::summarize($insight);

        $verdict = match (true) {
            ! $reading['valid'] => 'reading invalid',
            $reading['approved'] => 'owner approved',
            default => 'no owner approval found',
        };

        return "{$verdict} ({$reading['confidence']}%): {$reading['summary']}";
    }

    /**
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, error: ?string}|null
     */
    private function ask(WorkOrder $workOrder, string $text): ?array
    {
        try {
            $response = (new OwnerApprovalReaderAgent)->prompt($text, timeout: 45);
        } catch (\Throwable $exception) {
            Log::warning('Owner approval reading failed; the work order will be read on the next sweep.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $ownerWords = $this->ownerMessages($workOrder)
            ->filter(fn (array $message): bool => $message['who'] === 'owner')
            ->map(fn (array $message): string => $this->normalizeQuote($message['text']))
            ->values()
            ->all();

        return $this->validate(match (true) {
            is_array($response) => $response,
            $response instanceof Arrayable => $response->toArray(),
            default => (array) $response,
        }, $ownerWords);
    }

    /**
     * An approval stands only on the owner's own words: the quote must appear
     * in a message the owner wrote, not in the office's, a note or the
     * approval comment. Compared loosely -- case, spacing and quote marks
     * collapsed -- because the model copies text, not bytes.
     *
     * @param  array<string, mixed>  $response
     * @param  list<string>  $ownerWords  Every owner-written message, normalized.
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string, source: ?string}>, error: ?string}
     */
    private function validate(array $response, array $ownerWords): array
    {
        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $summary = Str::limit(trim((string) data_get($response, 'summary', '')), 597);
        $rawProblems = data_get($response, 'problems');
        $problems = [];
        $error = null;

        if (! is_array($rawProblems)) {
            $error = 'The answer carried no list of problems.';
        } else {
            foreach ($rawProblems as $problem) {
                $item = trim((string) data_get($problem, 'item', ''));
                $decision = trim((string) data_get($problem, 'decision', ''));
                $evidence = trim((string) data_get($problem, 'evidence', ''));
                $source = trim((string) data_get($problem, 'source', ''));

                if ($item === '' || ! in_array($decision, self::DECISIONS, true)) {
                    $error = 'A problem had no name or an unknown decision.';
                    break;
                }

                if ($decision !== 'not_mentioned' && $evidence === '') {
                    $error = "\"{$item}\" was marked {$decision} without the owner's words quoted.";
                    break;
                }

                if ($decision === 'approved' && ! $this->quotedFromOwner($evidence, $ownerWords)) {
                    $error = "\"{$item}\" was marked approved with words not found in the owner's own messages.";
                    break;
                }

                $problems[] = [
                    'item' => Str::limit($item, 117),
                    'decision' => $decision,
                    'evidence' => $evidence === '' ? null : Str::limit($evidence, 497),
                    'source' => $source === '' ? null : Str::limit($source, 117),
                ];
            }
        }

        $anyApproved = collect($problems)->contains(fn (array $problem): bool => $problem['decision'] === 'approved');
        $valid = $error === null && $problems !== [];

        return [
            'approved' => $valid && $anyApproved && (bool) data_get($response, 'approved', false),
            'valid' => $valid,
            'confidence' => $confidence,
            'summary' => $summary,
            'problems' => $problems,
            'error' => $error ?? ($problems === [] ? 'The answer listed no problems.' : null),
        ];
    }

    /**
     * @return Collection<int, array{when: string, subject: string, body: string}>
     */
    private function notes(WorkOrder $workOrder): Collection
    {
        return WorkOrderNotes::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrderNotes $note): array => [
                'when' => $this->when($note->date ?: $note->created_at, false),
                'subject' => $this->excerpt($note->subject, 120),
                'body' => $this->excerpt($note->body, self::MAX_NOTE),
            ])
            ->filter(fn (array $note): bool => $note['body'] !== '')
            ->values();
    }

    /**
     * The owner's SMS threads and emails on this work order, merged, oldest
     * first. is_read on work_order_conversations is the direction: true = the
     * office wrote it (ChatbotEventProcessor sets it to ! inbound).
     *
     * @return Collection<int, array{who: string, when: string, channel: string, text: string, at: string}>
     */
    private function ownerMessages(WorkOrder $workOrder): Collection
    {
        $texts = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->whereIn('conversation_type', ['owner', 'vendor_owner'])
            ->orderBy('id')
            ->get(['id', 'message', 'is_read', 'created_at'])
            ->map(fn (Conversation $message): array => [
                'who' => $message->is_read ? 'office' : 'owner',
                'when' => $this->when($message->created_at, true),
                'channel' => 'SMS',
                'text' => $this->excerpt($message->message, self::MAX_MESSAGE),
                'at' => $this->sortKey($message->created_at),
            ]);

        $emails = OwnerEmailNotification::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get(['id', 'direction', 'body_text', 'created_at'])
            ->map(fn (OwnerEmailNotification $email): array => [
                'who' => $email->direction === 'inbound' ? 'owner' : 'office',
                'when' => $this->when($email->created_at, true),
                'channel' => 'email',
                'text' => $this->excerpt($email->body_text, self::MAX_MESSAGE),
                'at' => $this->sortKey($email->created_at),
            ]);

        return $texts->concat($emails)
            ->filter(fn (array $message): bool => $message['text'] !== '')
            ->sortBy('at')
            ->values();
    }

    /**
     * @param  list<string>  $ownerWords
     */
    private function quotedFromOwner(string $evidence, array $ownerWords): bool
    {
        $quote = $this->normalizeQuote($evidence);

        if ($quote === '') {
            return false;
        }

        foreach ($ownerWords as $words) {
            if ($words !== '' && str_contains($words, $quote)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeQuote(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = trim($text, " \t\n\r\0\x0B\"'“”‘’");

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function sortKey(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function when(mixed $value, bool $withTime): string
    {
        try {
            $moment = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $withTime ? $moment->format('Y-m-d H:i') : $moment->toDateString();
    }

    private function excerpt(?string $value, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $value)), $limit);
    }

    private function record(WorkOrder $workOrder, AiInsight $insight): void
    {
        try {
            activity(self::LOG_NAME)
                ->performedOn($workOrder)
                ->event(data_get($insight->data, 'approved') ? 'owner_approval_found' : 'owner_approval_not_found')
                ->withProperties([
                    'confidence' => (int) $insight->confidence,
                    'valid' => (bool) data_get($insight->data, 'valid'),
                    'messages_read' => (int) data_get($insight->data, 'messages_read'),
                ])
                ->log(Str::limit(self::describe($insight), 250));
        } catch (\Throwable $exception) {
            Log::warning('Recording the owner approval reading failed.', ['work_order_id' => $workOrder->id, 'error' => $exception->getMessage()]);
        }
    }
}
