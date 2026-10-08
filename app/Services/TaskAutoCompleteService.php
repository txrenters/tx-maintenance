<?php

namespace App\Services;

use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\JobberVisit;
use App\Models\Scopes\AttachmentScope;
use App\Models\Scopes\CalendarScope;
use App\Models\Scopes\TaskScope;
use App\Models\ServiceSchedule;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderTask;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ticks THMP checklist tasks the database already proves done.
 *
 * Service THMP asked (2026-10-08) for six of their tasks to stop waiting on
 * a tap in the field: the three scheduling tasks, "Have you Completed the
 * Repair", and the before/after photo uploads. Each template line carries a
 * trigger key (picked on the Task Templates page); this service checks the
 * proof for each key and ticks the box.
 *
 * It ticks the box and nothing more. Earl's rule: the work order's service
 * status never moves and nothing is pushed to PropertyWare, so the cascade
 * in API\TaskController is deliberately not called. A person still moves
 * the status. A task a person un-ticked keeps its auto_completed_at and is
 * never ticked again.
 *
 * Scope is THMP work orders only: one with a Jobber job, or with THMP among
 * its vendors. The older `tasks:auto-complete` command keeps ticking the
 * name-matched data-entry tasks for every vendor as before.
 */
class TaskAutoCompleteService
{
    public const TENANT_CONTACTED = 'tenant_contacted';

    public const SCHEDULE_START_SET = 'schedule_start_set';

    public const SCHEDULE_END_SET = 'schedule_end_set';

    public const JOBBER_JOB_COMPLETED = 'jobber_job_completed';

    public const BEFORE_PHOTO_ADDED = 'before_photo_added';

    public const AFTER_PHOTO_ADDED = 'after_photo_added';

    public const LOG_NAME = 'task_automation';

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
        self::JOBBER_JOB_COMPLETED => 'Jobber job completed',
        self::BEFORE_PHOTO_ADDED => 'A before photo is on the work order',
        self::AFTER_PHOTO_ADDED => 'An after photo is on the work order',
    ];

    /**
     * Proof looked up once per work order and reused across its tasks.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(private JobberJobLocator $jobs) {}

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
     * THMP work orders only: linked to a Jobber job, or THMP is a vendor.
     */
    public static function inScope(WorkOrder $workOrder): bool
    {
        if (in_array($workOrder->status, WorkOrder::CLOSED_STATUSES, true)) {
            return false;
        }

        return filled($workOrder->jobber_job_gid) || Vendor::isThmpAssignedToWorkOrder($workOrder->id);
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
     * Tick every task whose proof is in. Returns how many were ticked.
     */
    public function run(WorkOrder $workOrder): int
    {
        $ticked = 0;

        foreach ($this->evaluate($workOrder) as $hit) {
            /** @var WorkOrderTask $task */
            $task = $hit['task'];

            try {
                $task->update([
                    'status' => 'completed',
                    'option' => $task->task->is_optional ? 'Yes' : $task->option,
                    'auto_completed_at' => now(),
                    'auto_complete_reason' => mb_substr($hit['reason'], 0, 255),
                ]);

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
                    ])
                    ->log('Ticked "'.$task->description.'": '.$hit['reason']);

                $ticked++;
            } catch (Throwable $e) {
                Log::warning('Task auto-complete could not tick a task.', [
                    'work_order_id' => $workOrder->id,
                    'work_order_task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ticked;
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
            ->with('task')
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
            self::JOBBER_JOB_COMPLETED => $this->jobberJobCompleted($workOrder),
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

    private function jobberJobCompleted(WorkOrder $workOrder): ?string
    {
        $completedAt = $this->jobberCompletedAt($workOrder);

        if ($completedAt) {
            return self::LABELS[self::JOBBER_JOB_COMPLETED].' ('.$this->day($completedAt).')';
        }

        $closed = $this->jobs->allForWorkOrder($workOrder)
            ->first(fn ($job) => mb_strtolower((string) $job->job_status) === 'closed');

        return $closed
            ? self::LABELS[self::JOBBER_JOB_COMPLETED].' (job closed in Jobber)'
            : null;
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
