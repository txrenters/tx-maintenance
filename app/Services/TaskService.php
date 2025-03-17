<?php

namespace App\Services;

use App\Models\ServiceStatus;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public static function createTasksForWorkOrder(WorkOrder $workOrder, bool $isEmergency, $serviceStatus_Id)
    {

        $workOrder->update(['service_status_id' => $serviceStatus_Id]);
        
        $now = now();

        // Fetch the task template based on emergency status and service status ID
        $taskTemplate = TaskTemplate::with(['currentServiceStatus', 'tasks'])
            ->whereHas('currentServiceStatus', function ($q) use ($serviceStatus_Id) {
                $q->where('id', $serviceStatus_Id); // Use service_status_id
            })
            ->where('is_current_service_status_emergency', $isEmergency)
            ->first();

        if (empty($taskTemplate->tasks)) {
            return;
        }

        $tasks = [];

        // Fetch vendors associated with the work order
        $vendors = $workOrder->vendors; // Assuming a relationship exists between WorkOrder and Vendor

        foreach ($taskTemplate->tasks as $task) {
            $taskDueDate = $now;

            // Calculate due date based on task's due_date field
            if ($task->due_date !== 'same day') {
                preg_match('/\d+/', $task->due_date, $matches);
                if (!empty($matches)) {
                    $days = (int) $matches[0];
                    $taskDueDate = $taskDueDate->addDays($days);
                }
            }

            // Assign task to WOC (Work Order Coordinator)
            if ($task->type === 'Woc') {
                $assignedUserId = User::role('woc')->first();
                if ($assignedUserId) {
                    $tasks[] = [
                        'due_date' => $taskDueDate,
                        'work_order_id' => $workOrder->id,
                        'assigned_user_id' => $assignedUserId->id,
                        'task_id' => $task->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            // Assign task to vendors
            elseif (!empty($vendors)) {
                foreach ($vendors as $vendor) {
                    $assignedUserId = User::with('vendor')
                        ->whereHas('vendor', function ($q) use ($vendor) {
                            $q->where('id', $vendor->id); // Use vendor ID instead of name
                        })
                        ->role('vendor')
                        ->first();

                    if ($assignedUserId) {
                        $tasks[] = [
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
        if (!empty($tasks)) {
            DB::table('work_order_tasks')->insert($tasks);
        }
    }
}