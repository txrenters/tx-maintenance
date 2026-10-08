<?php

namespace App\Services;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Support\Facades\Log;

/**
 * What ticking a checklist task does to its work order, as the template
 * defines it: nothing for a "Not Changed" line; for a line that names a
 * status (or a Yes/No line whose answer does), the work order moves there,
 * the new status's tasks are generated, and PropertyWare is told.
 *
 * Lifted out of API\TaskController so a system tick (TaskAutoCompleteService)
 * and a person's tick run the very same code. The behaviour is the
 * controller's, unchanged: a "Yes" first clears the remaining pending tasks,
 * a "Closed" next status closes the work order, and Crystal Creek work
 * orders never reach PropertyWare.
 */
class TaskCompletionService
{
    public function __construct(private PropertyWareService $propertyWare) {}

    /**
     * The service status the template moves the work order to on completion,
     * from the Yes/No branch for optional lines, else the line itself.
     *
     * @return array{0: int|null, 1: bool|null} [next_service_status_id, is_emergency]
     */
    public function resolveNextStatus(WorkOrderTask $task, ?string $option): array
    {
        if (! empty($option)) {
            if ($option === 'Yes') {
                return [
                    $task->task->taskDetailYesOption?->task_service_status_id,
                    $task->task->taskDetailYesOption?->is_task_service_status_emergency,
                ];
            }

            return [
                $task->task->taskDetailNoOption?->task_service_status_id,
                $task->task->taskDetailNoOption?->is_task_service_status_emergency,
            ];
        }

        return [
            $task->task->next_service_status_id,
            $task->task->is_emergency,
        ];
    }

    /**
     * Whether completing this task (with the given option) moves the work
     * order to a different service status, i.e. it is not a "Not Changed" line.
     */
    public function changesServiceStatus(WorkOrderTask $task, ?string $option): bool
    {
        [$nextServiceId] = $this->resolveNextStatus($task, $option);

        $status = ServiceStatus::find($nextServiceId);

        return $status !== null && $status->name !== 'Not Changed';
    }

    /**
     * Apply the side effects of completing a task. Returns the status the
     * work order moved to, or null when it stayed put.
     */
    public function applyCompletionEffects(WorkOrderTask $task, ?string $option): ?ServiceStatus
    {
        [$nextServiceId, $isEmergency] = $this->resolveNextStatus($task, $option);

        if ($option === 'Yes') {
            WorkOrderTask::where('work_order_id', $task->work_order->id)
                ->whereNot('status', 'completed')
                ->delete();
        }

        return $this->changeServiceStatus($task->work_order, $nextServiceId, $isEmergency);
    }

    private function changeServiceStatus(WorkOrder $workOrder, ?int $nextServiceId, ?bool $isEmergency): ?ServiceStatus
    {
        $serviceStatus = ServiceStatus::find($nextServiceId);

        if (! $serviceStatus) {
            return null;
        }

        if ($serviceStatus->name === 'Closed') {
            $this->closeWorkOrder($workOrder, $serviceStatus);

            return $serviceStatus;
        }

        if ($serviceStatus->name === 'Not Changed') {
            return null;
        }

        $workOrder->update([
            'is_emergency' => $isEmergency,
        ]);

        TaskService::createTasksForWorkOrder($workOrder, $isEmergency, $nextServiceId);

        if (! $workOrder->isCrystalCreek()) {
            $this->propertyWare->updateServiceStatus($workOrder, $serviceStatus);
        }

        return $serviceStatus;
    }

    /**
     * Close the work order when a "Close Work Order" task is completed.
     *
     * This mirrors the manual close (status, service status and completion
     * date) and syncs the closure to PropertyWare.
     */
    private function closeWorkOrder(WorkOrder $workOrder, ServiceStatus $serviceStatus): void
    {
        $workOrder->update([
            'status' => 'Closed',
            'service_status_id' => $serviceStatus->id,
            'completed_date' => now()->toDateString(),
        ]);

        // A closed work order's checklist is finished by definition: complete
        // (never delete — the rows are the audit trail) whatever is left. Bare
        // query update so the completion cascade cannot re-enter.
        WorkOrderTask::where('work_order_id', $workOrder->id)
            ->where('status', '!=', 'completed')
            ->update(['status' => 'completed']);

        if (! $workOrder->isCrystalCreek()) {
            $conversationUrl = route('conversation.show', $workOrder->id);

            $this->propertyWare->closeWorkOrder($workOrder, $conversationUrl);
        }

        Log::info('Work order closed via task completion: ', ['work_order_id' => $workOrder->id]);
    }
}
