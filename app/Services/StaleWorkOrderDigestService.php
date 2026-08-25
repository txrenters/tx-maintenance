<?php

namespace App\Services;

use App\Ai\Agents\StaleWorkOrderAgent;
use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * On-demand "why is this stuck" summary for one stale work order on the
 * open-over-30 report. Cached for a day per work order (BoardSummaryService
 * precedent) so repeated clicks and re-renders never re-spend AI; failures
 * are returned uncached so the next click simply tries again.
 *
 * Deliberately per-row and on-demand — never run across the whole report in
 * one request. The report hydrates every open work order; enriching all of
 * them at once is exactly the payload pattern that has taken this app down
 * before (see BoardSummaryService's docblock).
 */
class StaleWorkOrderDigestService
{
    private const CACHE_SECONDS = 86400;

    /**
     * Most recent messages included in the dossier, across all threads.
     */
    private const RECENT_MESSAGES = 5;

    private const RECENT_NOTES = 3;

    private const MAX_TASKS = 8;

    public function enabled(): bool
    {
        return (bool) config('services.ai.stale_digest', true);
    }

    /**
     * @return array{available: bool, stuck_reason: ?string, next_action: ?string}
     */
    public function analyze(WorkOrder $workOrder, bool $refresh = false): array
    {
        if (! $this->enabled() || ! AiSettings::ready()) {
            return ['available' => false, 'stuck_reason' => null, 'next_action' => null];
        }

        $cacheKey = 'stale_digest.'.$workOrder->id;

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $digest = $this->generate($workOrder);

        if ($digest === null) {
            // Uncached on purpose: the next click retries instead of pinning
            // a transient AI failure for a day.
            return ['available' => false, 'stuck_reason' => null, 'next_action' => null];
        }

        Cache::put($cacheKey, $digest, self::CACHE_SECONDS);

        return $digest;
    }

    /**
     * @return array{available: bool, stuck_reason: string, next_action: string}|null
     */
    private function generate(WorkOrder $workOrder): ?array
    {
        try {
            $response = (new StaleWorkOrderAgent)->prompt($this->buildPrompt($workOrder), timeout: 60);

            return [
                'available' => true,
                'stuck_reason' => Str::limit(trim((string) data_get($response, 'stuck_reason')), 300),
                'next_action' => Str::limit(trim((string) data_get($response, 'next_action')), 300),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Stale work order digest failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function buildPrompt(WorkOrder $workOrder): string
    {
        $workOrder->loadMissing(['service_status', 'vendors', 'service_schedules', 'tasks']);

        $lines = [
            'STALE WORK ORDER DOSSIER',
            'Today: '.now()->format('Y-m-d'),
            '',
            'WO #'.$workOrder->work_order_no,
            'Issue: '.Str::limit(trim((string) $workOrder->description), 400),
            'Status: '.($workOrder->service_status?->name ?? $workOrder->status ?? 'unknown'),
            'Created: '.($workOrder->created_date ?? 'unknown'),
            filled($workOrder->type) ? 'Type: '.$workOrder->type : null,
            filled($workOrder->location) ? 'Location: '.$workOrder->location : null,
            $workOrder->is_emergency ? 'Flagged as EMERGENCY.' : null,
            filled($workOrder->latest_update_comments) ? 'Latest update comment: '.Str::limit(trim((string) $workOrder->latest_update_comments), 300) : null,
        ];

        $lines[] = '';
        $lines[] = $workOrder->vendors->isEmpty()
            ? 'VENDORS: none assigned.'
            : 'VENDORS:';

        foreach ($workOrder->vendors as $vendor) {
            $lines[] = sprintf(
                '- %s (estimate: %s, scheduled end: %s)',
                $vendor->name,
                $vendor->pivot->cost_estimate ?? 'none',
                $vendor->pivot->scheduled_end_date ?? 'none',
            );
        }

        $lines[] = $workOrder->service_schedules->isEmpty()
            ? 'SCHEDULES: none ever set.'
            : 'SCHEDULES:';

        foreach ($workOrder->service_schedules as $schedule) {
            $lines[] = sprintf('- %s to %s (%s)', $schedule->scheduled_date, $schedule->scheduled_end_date ?? '—', $schedule->status);
        }

        $openTasks = $workOrder->tasks->take(self::MAX_TASKS);

        $lines[] = $openTasks->isEmpty() ? 'TASKS: none.' : 'TASKS:';

        foreach ($openTasks as $task) {
            $lines[] = sprintf('- %s [%s, due %s]', Str::limit((string) ($task->name ?? $task->title ?? 'task'), 80), $task->status ?? 'unknown', $task->due_date ?? 'no date');
        }

        // Staff-only digest: dashboard notes are all private (internal) and
        // are exactly the ones that explain why a work order is stuck.
        $notes = $workOrder->notes()
            ->latest('id')
            ->limit(self::RECENT_NOTES)
            ->get(['subject', 'body', 'created_at']);

        $lines[] = $notes->isEmpty() ? 'NOTES: none.' : 'RECENT NOTES:';

        foreach ($notes->reverse() as $note) {
            $lines[] = sprintf(
                '- %s: %s (%s)',
                Str::limit(trim((string) $note->subject), 60),
                Str::limit(trim(strip_tags((string) $note->body)), 200),
                optional($note->created_at)->format('Y-m-d'),
            );
        }

        // All threads, newest few messages — run scope-free: this is called
        // from a staff-only endpoint but must not narrow by the acting user.
        $messages = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_MESSAGES)
            ->get(['message', 'conversation_type', 'is_read', 'created_at']);

        $lines[] = $messages->isEmpty() ? 'MESSAGES: none.' : 'MOST RECENT MESSAGES (newest last):';

        foreach ($messages->reverse() as $message) {
            $lines[] = sprintf(
                '- %s %s (%s): "%s"',
                $message->is_read ? 'us ->' : 'them ->',
                $message->conversation_type ?: 'unknown',
                Carbon::parse($message->created_at)->format('Y-m-d'),
                Str::limit(trim(preg_replace('/\s+/', ' ', (string) $message->message)), 200),
            );
        }

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }
}
