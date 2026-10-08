<?php

namespace App\Services;

use App\Ai\Agents\RepairCompletionJudgeAgent;
use App\Models\AiInsight;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\JobberVisit;
use App\Models\Scopes\AttachmentScope;
use App\Models\Scopes\CalendarScope;
use App\Models\Scopes\TaskScope;
use App\Models\ServiceSchedule;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderTask;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ticks vendor checklist tasks the database already proves done.
 *
 * Service THMP asked (2026-10-08) for six of their tasks to stop waiting on
 * a tap in the field: the three scheduling tasks, "Have you Completed the
 * Repair", and the before/after photo uploads; Earl widened it to every
 * vendor on the portal the next day. Each template line carries a trigger
 * key (picked on the Task Templates page); this service checks the proof
 * for each key and ticks the box.
 *
 * A tick does what the template says it does (TaskCompletionService): a
 * "Not Changed" line only gets ticked; a line whose Task Done Service Status
 * names a status moves the work order there exactly as the crew's own tick
 * would — PropertyWare told, the next status's tasks generated, a Yes
 * clearing what is still pending. Lines that only tick go first and one
 * status move is applied per pass, after which the work order is read again
 * so the tasks the move generated get their turn. A task a person un-ticked
 * keeps its auto_completed_at and is never ticked again.
 *
 * Scope is any open work order with a vendor or a Jobber job. The older
 * `tasks:auto-complete` command keeps ticking the name-matched data-entry
 * tasks as before.
 */
class TaskAutoCompleteService
{
    public const TENANT_CONTACTED = 'tenant_contacted';

    public const SCHEDULE_START_SET = 'schedule_start_set';

    public const SCHEDULE_END_SET = 'schedule_end_set';

    public const REPAIR_COMPLETED = 'repair_completed';

    public const BEFORE_PHOTO_ADDED = 'before_photo_added';

    public const AFTER_PHOTO_ADDED = 'after_photo_added';

    public const LOG_NAME = 'task_automation';

    /**
     * Passes per run: each status move generates new tasks that may already
     * have their proof in, and this bounds how far one run may travel.
     */
    private const MAX_PASSES = 3;

    /**
     * Trigger key => the label the Task Templates dropdown and the task's
     * "Auto" badge show. Order is the dropdown order.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::TENANT_CONTACTED => 'Tenant texted about the appointment',
        self::SCHEDULE_START_SET => 'Service Schedule has a start date',
        self::SCHEDULE_END_SET => 'Service Schedule has an end date',
        self::REPAIR_COMPLETED => 'Repair finished (Jobber done + tech note, or an after photo passing the AI check)',
        self::BEFORE_PHOTO_ADDED => 'A before photo is on the work order',
        self::AFTER_PHOTO_ADDED => 'An after photo is on the work order',
    ];

    /**
     * Proof looked up once per work order and reused across its tasks.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        private JobberJobLocator $jobs,
        private TaskCompletionService $completion,
    ) {}

    /**
     * The dropdown's options.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(self::LABELS),
            self::LABELS,
        );
    }

    public static function isEnabled(): bool
    {
        return (bool) config('services.tasks.auto_complete_enabled');
    }

    /**
     * Any open work order with a vendor on it, or a Jobber job: those are the
     * ones with a vendor checklist to tick.
     */
    public static function inScope(WorkOrder $workOrder): bool
    {
        if (in_array($workOrder->status, WorkOrder::CLOSED_STATUSES, true)) {
            return false;
        }

        return filled($workOrder->jobber_job_gid) || $workOrder->vendors()->exists();
    }

    /**
     * The pending tasks on this work order whose proof is in, without
     * ticking anything. What `run()` would do, and what --dry-run prints.
     *
     * @return Collection<int, array{task: WorkOrderTask, trigger: string, reason: string}>
     */
    public function evaluate(WorkOrder $workOrder): Collection
    {
        $this->memo = [];

        if (! self::isEnabled() || ! self::inScope($workOrder)) {
            return new Collection;
        }

        $hits = new Collection;

        foreach ($this->candidates($workOrder) as $task) {
            $trigger = (string) $task->task->auto_complete_trigger;

            try {
                $reason = $this->proof($trigger, $workOrder, $task);
            } catch (Throwable $e) {
                // Includes a repair-judge failure: the task is simply held.
                Log::warning('Task auto-complete could not check a task.', [
                    'work_order_id' => $workOrder->id,
                    'work_order_task_id' => $task->id,
                    'trigger' => $trigger,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($reason !== null) {
                $hits->push(['task' => $task, 'trigger' => $trigger, 'reason' => $reason]);
            }
        }

        return $hits;
    }

    /**
     * Tick every task whose proof is in, applying what the template says a
     * tick does. Returns how many were ticked.
     */
    public function run(WorkOrder $workOrder): int
    {
        $ticked = 0;

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            if ($pass > 1) {
                $workOrder->refresh();
            }

            $hits = $this->evaluate($workOrder);

            if ($hits->isEmpty()) {
                break;
            }

            // Mirrors bulkComplete: the lines that only tick go first, then a
            // single status-moving line, because the move regenerates the
            // checklist (and a Yes clears it) under the others' feet.
            [$moving, $routine] = $hits->partition(
                fn (array $hit) => $this->completion->changesServiceStatus($hit['task'], $this->optionFor($hit['task']))
            );

            foreach ($routine as $hit) {
                $ticked += $this->tick($workOrder, $hit, false) ? 1 : 0;
            }

            $first = $moving->first();

            if ($first === null || ! $this->tick($workOrder, $first, true)) {
                break;
            }

            $ticked++;
        }

        return $ticked;
    }

    /**
     * The answer a system tick gives a Yes/No line. The proof is always the
     * "yes" of the question, so that is the branch the template takes.
     */
    private function optionFor(WorkOrderTask $task): ?string
    {
        return $task->task->is_optional ? 'Yes' : $task->option;
    }

    /**
     * @param  array{task: WorkOrderTask, trigger: string, reason: string}  $hit
     */
    private function tick(WorkOrder $workOrder, array $hit, bool $applyTemplate): bool
    {
        $task = $hit['task'];
        $option = $this->optionFor($task);

        try {
            $task->update([
                'status' => 'completed',
                'option' => $option,
                'auto_completed_at' => now(),
                'auto_complete_reason' => mb_substr($hit['reason'], 0, 255),
            ]);

            $movedTo = $applyTemplate ? $this->completion->applyCompletionEffects($task, $option) : null;

            activity(self::LOG_NAME)
                ->performedOn($workOrder)
                ->event('task.auto_completed')
                ->withProperties([
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'work_order_task_id' => $task->id,
                    'task' => $task->description,
                    'trigger' => $hit['trigger'],
                    'reason' => $hit['reason'],
                    'moved_to' => $movedTo?->name,
                ])
                ->log('Ticked "'.$task->description.'": '.$hit['reason'].($movedTo ? ' — moved to '.$movedTo->name : ''));

            return true;
        } catch (Throwable $e) {
            Log::warning('Task auto-complete could not tick a task.', [
                'work_order_id' => $workOrder->id,
                'work_order_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pending template tasks with a trigger that the system never ticked
     * before. TaskScope is lifted because the schedule-save hook runs as
     * whoever saved the schedule, and a vendor login only sees its own rows.
     *
     * @return Collection<int, WorkOrderTask>
     */
    private function candidates(WorkOrder $workOrder): Collection
    {
        return WorkOrderTask::withoutGlobalScope(TaskScope::class)
            ->with(['work_order', 'task.taskDetailYesOption', 'task.taskDetailNoOption'])
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->whereNull('auto_completed_at')
            ->whereNotNull('task_id')
            ->whereHas('task', fn ($template) => $template->whereNotNull('auto_complete_trigger'))
            ->orderBy('id')
            ->get();
    }

    /**
     * The proof for one trigger, as the sentence stored on the task, or null
     * when it is not in yet.
     */
    private function proof(string $trigger, WorkOrder $workOrder, WorkOrderTask $task): ?string
    {
        return match ($trigger) {
            self::SCHEDULE_START_SET => $this->scheduleStart($workOrder),
            self::SCHEDULE_END_SET => $this->scheduleEnd($workOrder),
            self::TENANT_CONTACTED => $this->tenantContacted($workOrder, $task),
            self::REPAIR_COMPLETED => $this->repairCompleted($workOrder),
            self::BEFORE_PHOTO_ADDED => $this->beforePhoto($workOrder),
            self::AFTER_PHOTO_ADDED => $this->afterPhoto($workOrder),
            default => null,
        };
    }

    /**
     * Schedules that still stand. CalendarScope is lifted for the same
     * reason as TaskScope above.
     *
     * @return Collection<int, ServiceSchedule>
     */
    private function schedules(WorkOrder $workOrder): Collection
    {
        return $this->memo['schedules'] ??= ServiceSchedule::withoutGlobalScope(CalendarScope::class)
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->get();
    }

    private function scheduleStart(WorkOrder $workOrder): ?string
    {
        $schedule = $this->schedules($workOrder)->first(fn (ServiceSchedule $row) => filled($row->scheduled_date));

        return $schedule
            ? self::LABELS[self::SCHEDULE_START_SET].' ('.$this->day($schedule->scheduled_date).')'
            : null;
    }

    /**
     * The schedule's own end date first; else the work order's projected end
     * (the PropertyWare field the task is named after, which the schedule
     * save and the import both fill).
     */
    private function scheduleEnd(WorkOrder $workOrder): ?string
    {
        $schedule = $this->schedules($workOrder)->first(fn (ServiceSchedule $row) => filled($row->scheduled_end_date));
        $end = $schedule?->scheduled_end_date ?? $workOrder->scheduled_end_date;

        return filled($end)
            ? self::LABELS[self::SCHEDULE_END_SET].' ('.$this->day($end).')'
            : null;
    }

    /**
     * The app's appointment text (claimed on the schedule), or any text
     * staff sent the tenant after this task appeared. is_read is the
     * direction marker on work_order_conversations: true means we sent it.
     */
    private function tenantContacted(WorkOrder $workOrder, WorkOrderTask $task): ?string
    {
        $notified = $this->schedules($workOrder)->first(fn (ServiceSchedule $row) => filled($row->tenant_notified_at));

        if ($notified) {
            return self::LABELS[self::TENANT_CONTACTED].' ('.$this->day($notified->tenant_notified_at).')';
        }

        $sent = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->where('is_read', true)
            ->where('created_at', '>=', $task->created_at)
            ->orderByDesc('id')
            ->first();

        return $sent
            ? 'Tenant texted by staff ('.$this->day($sent->created_at).')'
            : null;
    }

    /**
     * THMP (a Jobber job about the work order) proves the repair through
     * Jobber plus the technician's note; an outside vendor, who only has the
     * portal, through an after photo the vision review passed.
     */
    private function repairCompleted(WorkOrder $workOrder): ?string
    {
        return $this->jobs->allForWorkOrder($workOrder)->isNotEmpty()
            ? $this->jobberJobCompleted($workOrder)
            : $this->afterPhotoPassedReview($workOrder);
    }

    /**
     * The outside vendor's proof: an "after" photo they uploaded that the
     * existing completion-photo review (ReviewCompletionPhoto, an AI vision
     * check) judged to plausibly show the problem fixed. A photo the review
     * doubted, or has not looked at yet, holds the task for a coordinator.
     */
    private function afterPhotoPassedReview(WorkOrder $workOrder): ?string
    {
        $afterIds = $this->attachments($workOrder)->where('type', 'after')->pluck('id');

        if ($afterIds->isEmpty()) {
            return null;
        }

        $passed = AiInsight::query()
            ->where('type', AiInsight::TYPE_PHOTO_REVIEW)
            ->where('subject_type', Attachments::class)
            ->whereIn('subject_id', $afterIds)
            ->get()
            ->first(fn (AiInsight $insight) => ($insight->data['verdict'] ?? null) === 'looks_resolved');

        if (! $passed) {
            Log::info('Repair tick held: no after photo has passed the photo review yet.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'after_photos' => $afterIds->count(),
            ]);

            return null;
        }

        $note = trim((string) ($passed->data['note'] ?? ''));

        return 'After photo passed the AI photo check ('.$this->day($passed->generated_at ?? $passed->created_at).')'
            .($note !== '' ? ': '.$note : '');
    }

    /**
     * A Jobber completion is half the proof: the crew taps Complete on
     * temporary fixes and return trips too, so the technician's notes are
     * read by RepairCompletionJudgeAgent and only a confident "finished"
     * ticks the task. The judge switched off = the completion decides alone.
     */
    private function jobberJobCompleted(WorkOrder $workOrder): ?string
    {
        $completedAt = $this->jobberCompletedAt($workOrder);

        if ($completedAt) {
            $completion = 'Jobber job completed ('.$this->day($completedAt).')';
        } else {
            $closed = $this->jobs->allForWorkOrder($workOrder)
                ->first(fn ($job) => mb_strtolower((string) $job->job_status) === 'closed');

            if (! $closed) {
                return null;
            }

            $completion = 'Jobber job closed';
        }

        if (! config('services.ai.repair_judge')) {
            return $completion;
        }

        $verdict = $this->repairVerdict($workOrder, $completedAt);

        return $verdict ? $completion.'; '.$verdict : null;
    }

    /**
     * What the judge made of the technician's notes, as the sentence stored
     * on the task when the repair is finished, else null. The verdict is
     * cached against the newest note and the completion time so a held
     * work order is not re-read every sweep until something changes.
     */
    private function repairVerdict(WorkOrder $workOrder, ?CarbonInterface $completedAt): ?string
    {
        $notes = WorkOrderJobberNote::query()
            ->where('work_order_id', $workOrder->id)
            ->whereNotNull('message')
            ->where('message', '!=', '')
            ->orderByDesc('jobber_created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($notes->isEmpty()) {
            Log::info('Repair tick held: the Jobber job is complete but the technician has not written a note yet.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
            ]);

            return null;
        }

        if (! AiSettings::ready()) {
            Log::warning('Repair tick held: no AI provider is configured to read the technician note.', [
                'work_order_id' => $workOrder->id,
            ]);

            return null;
        }

        $key = sprintf(
            'task-auto-complete:repair-judge:%d:%s:%s',
            $workOrder->id,
            $notes->first()->jobber_note_gid,
            $completedAt?->getTimestamp() ?? 'closed',
        );

        $verdict = Cache::remember($key, now()->addDay(), fn () => $this->askRepairJudge($workOrder, $notes));

        if ($verdict['completed']) {
            return "tech note says done ({$verdict['confidence']}%): {$verdict['reason']}";
        }

        Log::info('Repair tick held: the technician note does not say the repair is finished.', [
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'verdict' => $verdict,
        ]);

        return null;
    }

    /**
     * @param  Collection<int, WorkOrderJobberNote>  $notes
     * @return array{completed: bool, confidence: int, reason: string}
     */
    private function askRepairJudge(WorkOrder $workOrder, Collection $notes): array
    {
        $prompt = implode("\n", [
            'WORK ORDER #'.$workOrder->work_order_no,
            'Description: '.Str::limit(trim((string) $workOrder->description), 1000),
            'Category: '.($workOrder->category ?: 'n/a'),
            '',
            'JOBBER: the visit or job is marked complete.',
            '',
            'TECHNICIAN NOTES (newest first):',
            ...$notes->map(fn (WorkOrderJobberNote $note) => sprintf(
                '- [%s] %s: %s',
                $note->jobber_created_at?->timezone('America/Chicago')->format('M j, Y g:i A') ?? 'undated',
                $note->author_name ?: 'Technician',
                Str::limit(trim((string) $note->message), 600),
            ))->all(),
        ]);

        try {
            $response = (new RepairCompletionJudgeAgent)->prompt($prompt, timeout: 30);
        } catch (Throwable $exception) {
            Log::warning('Repair judge failed; the repair tick is held.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            // Not cached: the next sweep asks again.
            throw $exception;
        }

        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $minimum = (int) config('services.ai.repair_min_confidence', 80);

        return [
            'completed' => (bool) data_get($response, 'completed', false) && $confidence >= $minimum,
            'confidence' => $confidence,
            'reason' => Str::limit(trim((string) data_get($response, 'reason', '')), 160),
        ];
    }

    /**
     * When the crew first finished: the earliest completed visit or job
     * completion across every Jobber job about the work order. Null while
     * nothing is done, which also sorts every Jobber photo as "before".
     */
    private function jobberCompletedAt(WorkOrder $workOrder): ?CarbonInterface
    {
        if (array_key_exists('completed_at', $this->memo)) {
            return $this->memo['completed_at'];
        }

        $jobs = $this->jobs->allForWorkOrder($workOrder);

        $times = $jobs
            ->map(fn ($job) => $job->completed_at)
            ->filter()
            ->map(fn ($time) => Carbon::parse($time));

        if ($jobs->isNotEmpty()) {
            $visit = JobberVisit::query()
                ->whereIn('jobber_job_id', $jobs->pluck('id'))
                ->whereNotNull('completed_at')
                ->min('completed_at');

            if ($visit) {
                $times->push(Carbon::parse($visit));
            }
        }

        return $this->memo['completed_at'] = $times->sortBy(fn (CarbonInterface $time) => $time->getTimestamp())->first();
    }

    private function beforePhoto(WorkOrder $workOrder): ?string
    {
        $typed = $this->attachments($workOrder)->where('type', 'before')->orderByDesc('id')->first();

        if ($typed) {
            return self::LABELS[self::BEFORE_PHOTO_ADDED].' (uploaded '.$this->day($typed->created_at).')';
        }

        $completedAt = $this->jobberCompletedAt($workOrder);

        $note = $this->jobberPhotoNotes($workOrder)
            ->when($completedAt, fn ($query) => $query->where('jobber_created_at', '<', $completedAt))
            ->orderByDesc('jobber_created_at')
            ->first();

        return $note
            ? self::LABELS[self::BEFORE_PHOTO_ADDED].' (Jobber photo, '.$this->day($note->jobber_created_at).')'
            : null;
    }

    private function afterPhoto(WorkOrder $workOrder): ?string
    {
        $typed = $this->attachments($workOrder)->where('type', 'after')->orderByDesc('id')->first();

        if ($typed) {
            return self::LABELS[self::AFTER_PHOTO_ADDED].' (uploaded '.$this->day($typed->created_at).')';
        }

        $completedAt = $this->jobberCompletedAt($workOrder);

        if (! $completedAt) {
            return null;
        }

        $note = $this->jobberPhotoNotes($workOrder)
            ->where('jobber_created_at', '>=', $completedAt)
            ->orderByDesc('jobber_created_at')
            ->first();

        return $note
            ? self::LABELS[self::AFTER_PHOTO_ADDED].' (Jobber photo, '.$this->day($note->jobber_created_at).')'
            : null;
    }

    private function attachments(WorkOrder $workOrder)
    {
        return Attachments::withoutGlobalScope(AttachmentScope::class)
            ->where('work_order_id', $workOrder->id);
    }

    /**
     * Jobber notes whose photo the note sync has downloaded onto this work
     * order. The note's own Jobber time says when the photo was taken.
     */
    private function jobberPhotoNotes(WorkOrder $workOrder)
    {
        return WorkOrderJobberNote::query()
            ->where('work_order_id', $workOrder->id)
            ->whereNotNull('jobber_created_at')
            ->whereHas('files', fn ($files) => $files->whereNotNull('attachment_id'));
    }

    private function day(mixed $time): string
    {
        return Carbon::parse($time)->timezone('America/Chicago')->format('M j, Y');
    }
}
