<?php

namespace App\Services;

use App\Models\Scopes\TaskScope;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskService
{
    /**
     * Work order types that route their WOC coordination tasks to a dedicated
     * user instead of the general work-order coordinator. Each entry names the
     * target user's email and the role that user must hold.
     *
     * @var array<string, array{email: string, role: string}>
     */
    private const WOC_ROUTING_BY_TYPE = [
        'Biweekly Lawn Services' => ['email' => 'xservice@txhomemp.com', 'role' => 'woc'],
        'Turnover' => ['email' => 'mc@texasrenters.com', 'role' => 'admin'],
    ];

    public static function createTasksForWorkOrder(WorkOrder $workOrder, bool $isEmergency, $serviceStatus_Id)
    {
        // Skip task creation if automated tasks are disabled
        if ($workOrder->skip_automated_tasks) {
            Log::info('Skipping automated task creation for work order', [
                'work_order_no' => $workOrder->work_order_no,
                'skip_automated_tasks' => true,
            ]);

            $workOrder->update(['service_status_id' => $serviceStatus_Id]);

            return;
        }

        $workOrder->update(['service_status_id' => $serviceStatus_Id]);

        $now = now();

        $taskTemplate = self::resolveTemplate($workOrder, $isEmergency, $serviceStatus_Id);

        if (empty($taskTemplate) || $taskTemplate->tasks->isEmpty()) {
            return;
        }

        $tasks = [];

        // Fetch vendors associated with the work order
        $vendors = $workOrder->vendors; // Assuming a relationship exists between WorkOrder and Vendor

        foreach ($taskTemplate->tasks as $task) {
            $taskDueDate = self::templateTaskDueDate($task, $workOrder, $now);

            // Assign task to WOC (Work Order Coordinator)
            if ($task->type === 'Woc') {
                $assignedUser = self::resolveWocAssignee($workOrder, $task, ! empty($taskTemplate->work_order_type));

                if ($assignedUser) {
                    $tasks[] = [
                        'description' => $task->name,
                        'due_date' => $taskDueDate,
                        'work_order_id' => $workOrder->id,
                        'assigned_user_id' => $assignedUser->id,
                        'task_id' => $task->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            // Assign task to vendors
            elseif (! empty($vendors)) {
                foreach ($vendors as $vendor) {
                    $assignedUserId = self::vendorUser($vendor);

                    if ($assignedUserId) {
                        $tasks[] = [
                            'description' => $task->name,
                            'due_date' => $taskDueDate,
                            'work_order_id' => $workOrder->id,
                            'assigned_user_id' => $assignedUserId->id,
                            'task_id' => $task->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        // Insert tasks into the database
        if (! empty($tasks)) {
            DB::table('work_order_tasks')->insert($tasks);
        }
    }

    /**
     * Give a vendor the checklist for the work order's current service status
     * when it was never generated for them. Status changes made in
     * PropertyWare — and vendors attached after an in-app status change —
     * skip createTasksForWorkOrder, leaving the vendor portal without the
     * "What needs to be done" checkboxes, and those checkboxes are the only
     * way a vendor can move the status forward.
     *
     * Only Vendor-type template tasks are created, and only for a vendor whose
     * user has no open task on the work order; a row that already exists for
     * the same template task — even soft-deleted, i.e. removed by a
     * coordinator — is never re-created. Safe to call repeatedly (the
     * PropertyWare sync does, every run) and never throws, so a failure here
     * cannot break vendor assignment or a sync loop.
     *
     * @param  array<int, int>  $vendorIds
     */
    public static function backfillVendorTasks(WorkOrder|int $workOrder, array $vendorIds): void
    {
        try {
            if (empty($vendorIds)) {
                return;
            }

            $workOrder = $workOrder instanceof WorkOrder ? $workOrder : WorkOrder::query()->find($workOrder);

            if (! $workOrder
                || $workOrder->skip_automated_tasks
                || $workOrder->status !== 'Open'
                || empty($workOrder->service_status_id)) {
                return;
            }

            $template = self::resolveTemplate($workOrder, (bool) $workOrder->is_emergency, $workOrder->service_status_id);

            $templateTasks = $template ? $template->tasks->where('type', 'Vendor') : collect();

            if ($templateTasks->isEmpty()) {
                return;
            }

            $now = now();

            foreach (Vendor::query()->whereIn('id', $vendorIds)->get() as $vendor) {
                if ($vendor->isOwnerPlaceholder()) {
                    continue;
                }

                $user = self::vendorUser($vendor);

                if (! $user) {
                    continue;
                }

                // A vendor who already has an open checklist keeps it untouched,
                // whichever status it was generated for.
                $hasOpenTasks = WorkOrderTask::withoutGlobalScope(TaskScope::class)
                    ->where('work_order_id', $workOrder->id)
                    ->where('assigned_user_id', $user->id)
                    ->where('status', '!=', 'completed')
                    ->exists();

                if ($hasOpenTasks) {
                    continue;
                }

                $existingTaskIds = WorkOrderTask::withoutGlobalScope(TaskScope::class)
                    ->withTrashed()
                    ->where('work_order_id', $workOrder->id)
                    ->where('assigned_user_id', $user->id)
                    ->whereNotNull('task_id')
                    ->pluck('task_id')
                    ->all();

                $rows = $templateTasks
                    ->reject(fn (Task $task) => in_array($task->id, $existingTaskIds))
                    ->map(fn (Task $task) => [
                        'description' => $task->name,
                        'due_date' => self::templateTaskDueDate($task, $workOrder, $now),
                        'work_order_id' => $workOrder->id,
                        'assigned_user_id' => $user->id,
                        'task_id' => $task->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if (! empty($rows)) {
                    DB::table('work_order_tasks')->insert($rows);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Vendor task backfill failed.', [
                'work_order_id' => $workOrder instanceof WorkOrder ? $workOrder->id : $workOrder,
                'vendor_ids' => $vendorIds,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Re-anchor the due dates of a work order's open template tasks to its
     * current schedule. Vendors usually set (or move) the service schedule
     * after the status tasks were generated, so the generated due dates would
     * otherwise stay stuck on the generation day. Mirrors the precedence used
     * at creation: "N days from start date" template tasks follow start_date;
     * everything else follows scheduled_end_date. Completed tasks and manually
     * created tasks (no template link) keep the dates a person gave them, and
     * tasks are left untouched when the work order has no schedule to anchor to.
     */
    public static function syncTaskDueDatesToSchedule(WorkOrder $workOrder): void
    {
        $openTasks = WorkOrderTask::withoutGlobalScope(TaskScope::class)
            ->with('task')
            ->where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->whereNotNull('task_id')
            ->get();

        foreach ($openTasks as $openTask) {
            $templateDueDate = (string) ($openTask->task?->due_date ?? '');
            $newDueDate = null;

            if (str_contains($templateDueDate, 'from start date')) {
                if ($workOrder->start_date) {
                    preg_match('/\d+/', $templateDueDate, $matches);
                    $days = ! empty($matches) ? (int) $matches[0] : 0;

                    $newDueDate = Carbon::parse($workOrder->start_date)->addDays($days);
                }
            } elseif (! empty($workOrder->scheduled_end_date)) {
                $newDueDate = Carbon::parse($workOrder->scheduled_end_date);
            }

            if ($newDueDate === null) {
                continue;
            }

            if ((string) $openTask->due_date !== $newDueDate->toDateString()) {
                $openTask->update(['due_date' => $newDueDate->toDateString()]);
            }
        }
    }

    /**
     * Resolve the user a WOC coordination task should be assigned to. Some work
     * order types route to a dedicated user (see WOC_ROUTING_BY_TYPE); otherwise
     * we honour an explicit template assignment and fall back to the first user
     * with the 'woc' role. The routed user must hold the configured role, and if
     * they are not found we fall back to the default so task creation never
     * silently drops a WOC task.
     *
     * On type-specific templates (e.g. the Turnover workflow) an explicit
     * per-task assignee wins over the type routing, since those templates name
     * the exact person responsible for each step.
     */
    private static function resolveWocAssignee(WorkOrder $workOrder, $task, bool $isTypeSpecificTemplate = false): ?User
    {
        if ($isTypeSpecificTemplate && $task->assigned_user_id) {
            $templateAssignee = User::find($task->assigned_user_id);

            if ($templateAssignee) {
                return $templateAssignee;
            }
        }

        $routedUser = self::routedWocUserForType(
            $workOrder->isTurnover() ? 'Turnover' : $workOrder->type
        );

        if ($routedUser) {
            return $routedUser;
        }

        // Prefer a user specifically assigned on the template task; otherwise
        // fall back to the first user with the 'woc' role (legacy behaviour).
        return $task->assigned_user_id
            ? User::find($task->assigned_user_id)
            : User::role('woc')->first();
    }

    /**
     * The work order types that route WOC tasks to a dedicated user.
     *
     * @return array<int, string>
     */
    public static function routedTypes(): array
    {
        return array_keys(self::WOC_ROUTING_BY_TYPE);
    }

    /**
     * The user WOC tasks for the given work order type should route to, or null
     * when the type has no routing rule or the configured user does not exist
     * with the required role.
     */
    public static function routedWocUserForType(?string $type): ?User
    {
        $routing = self::WOC_ROUTING_BY_TYPE[$type] ?? null;

        if (! $routing) {
            return null;
        }

        return User::role($routing['role'])
            ->where('email', $routing['email'])
            ->first();
    }

    /**
     * The template that generates tasks for this status/emergency combination.
     * Work order types with their own template set (e.g. Turnover) use only
     * that set: a status they skip generates no tasks rather than falling
     * back to the generic workflow. Types without a dedicated set use the
     * generic (null work_order_type) templates. Turnover is matched via
     * isTurnover() (type OR category) since PropertyWare data carries it in
     * either field.
     */
    private static function resolveTemplate(WorkOrder $workOrder, bool $isEmergency, int|string $serviceStatusId): ?TaskTemplate
    {
        $templateType = $workOrder->isTurnover() ? 'Turnover' : $workOrder->type;

        $hasTypeSpecificTemplates = ! empty($templateType)
            && TaskTemplate::where('work_order_type', $templateType)
                ->where('is_current_service_status_emergency', $isEmergency)
                ->exists();

        return TaskTemplate::with(['currentServiceStatus', 'tasks'])
            ->whereHas('currentServiceStatus', function ($q) use ($serviceStatusId) {
                $q->where('id', $serviceStatusId);
            })
            ->where('is_current_service_status_emergency', $isEmergency)
            ->when(
                $hasTypeSpecificTemplates,
                fn ($q) => $q->where('work_order_type', $templateType),
                fn ($q) => $q->whereNull('work_order_type')
            )
            ->first();
    }

    /**
     * Due date for a template task: "N days from start date" rules anchor to
     * the work order's start date (falling back to today), any scheduled end
     * date wins next, and otherwise "same day" / "N days" counts from today.
     * $now is never mutated.
     */
    private static function templateTaskDueDate(Task $task, WorkOrder $workOrder, Carbon $now): Carbon
    {
        if ($task->due_date && str_contains($task->due_date, 'from start date')) {
            preg_match('/\d+/', $task->due_date, $matches);
            $days = ! empty($matches) ? (int) $matches[0] : 0;

            return ($workOrder->start_date ? Carbon::parse($workOrder->start_date) : $now->copy())
                ->addDays($days);
        }

        if (! empty($workOrder->scheduled_end_date)) {
            return Carbon::parse($workOrder->scheduled_end_date);
        }

        $taskDueDate = $now->copy();

        if ($task->due_date !== 'same day') {
            preg_match('/\d+/', (string) $task->due_date, $matches);
            if (! empty($matches)) {
                $taskDueDate = $taskDueDate->addDays((int) $matches[0]);
            }
        }

        return $taskDueDate;
    }

    /**
     * The vendor-role user a vendor's tasks are assigned to (the account the
     * vendor portal filters by); null when the vendor has no such user.
     */
    private static function vendorUser(Vendor $vendor): ?User
    {
        return User::whereHas('vendor', function ($q) use ($vendor) {
            $q->where('id', $vendor->id);
        })
            ->role('vendor')
            ->first();
    }
}
