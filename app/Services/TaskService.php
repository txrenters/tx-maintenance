<?php

namespace App\Services;

use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WorkOrder;
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

        // Check if work order has a scheduled date
        $hasScheduledDate = ! empty($workOrder->scheduled_end_date);

        // Fetch the task template based on emergency status and service status ID.
        // Work order types with their own template set (e.g. Turnover) use only
        // that set: a status they skip generates no tasks rather than falling
        // back to the generic workflow. Types without a dedicated set use the
        // generic (null work_order_type) templates as before. Turnover is
        // matched via isTurnover() (type OR category) since PropertyWare data
        // carries it in either field.
        $templateType = $workOrder->isTurnover() ? 'Turnover' : $workOrder->type;

        $hasTypeSpecificTemplates = ! empty($templateType)
            && TaskTemplate::where('work_order_type', $templateType)
                ->where('is_current_service_status_emergency', $isEmergency)
                ->exists();

        $taskTemplate = TaskTemplate::with(['currentServiceStatus', 'tasks'])
            ->whereHas('currentServiceStatus', function ($q) use ($serviceStatus_Id) {
                $q->where('id', $serviceStatus_Id); // Use service_status_id
            })
            ->where('is_current_service_status_emergency', $isEmergency)
            ->when(
                $hasTypeSpecificTemplates,
                fn ($q) => $q->where('work_order_type', $templateType),
                fn ($q) => $q->whereNull('work_order_type')
            )
            ->first();

        if (empty($taskTemplate) || $taskTemplate->tasks->isEmpty()) {
            return;
        }

        $tasks = [];

        // Fetch vendors associated with the work order
        $vendors = $workOrder->vendors; // Assuming a relationship exists between WorkOrder and Vendor

        foreach ($taskTemplate->tasks as $task) {
            // "N days from start date" due dates are anchored to the work
            // order's start date (falling back to today when it is missing),
            // regardless of any scheduled end date.
            if ($task->due_date && str_contains($task->due_date, 'from start date')) {
                preg_match('/\d+/', $task->due_date, $matches);
                $days = ! empty($matches) ? (int) $matches[0] : 0;

                $taskDueDate = ($workOrder->start_date ? Carbon::parse($workOrder->start_date) : $now->copy())
                    ->addDays($days);
            }
            // If scheduled_end_date exists, use it directly; otherwise calculate from current date
            elseif ($hasScheduledDate) {
                $taskDueDate = Carbon::parse($workOrder->scheduled_end_date);
            } else {
                $taskDueDate = $now;

                // Calculate due date based on task's due_date field
                if ($task->due_date !== 'same day') {
                    preg_match('/\d+/', $task->due_date, $matches);
                    if (! empty($matches)) {
                        $days = (int) $matches[0];
                        $taskDueDate = $taskDueDate->addDays($days);
                    }
                }
            }

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
                    $assignedUserId = User::with('vendor')
                        ->whereHas('vendor', function ($q) use ($vendor) {
                            $q->where('id', $vendor->id); // Use vendor ID instead of name
                        })
                        ->role('vendor')
                        ->first();

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
}
