<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    public function tasks(WorkOrder $workOrder)
    {
        $workOrder->load(['tasks.task.taskDetails.taskServiceStatus', 'tasks.task.nextServiceStatus', 'tasks.assigned_user']);

        return response()->json($workOrder, 200);
    }

    public function service_status_change(Request $request, WorkOrder $workOrder)
    {
        $service_status = ServiceStatus::find($request->service_status_id);

        if ($service_status->id == 1) {
            $workOrder->update([
                'is_emergency' => null,
                'service_status_id' => 1,
                'local_status' => 'Created',
            ]);

            DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->delete(); // start a new work order so reset all
            WorkOrderTask::where('work_order_id', $workOrder->id)->delete();

        } else {
            WorkOrderTask::where('work_order_id', $workOrder->id)->where('status', '!=', 'completed')->delete();
            TaskService::createTasksForWorkOrder($workOrder, $request->is_emergency == 'Emergency', $request->service_status_id);
        }

        if (! $workOrder->isCrystalCreek()) {
            $propertyWare = new PropertyWareService;
            $propertyWare->updateServiceStatus($workOrder, $service_status);
        }

        return redirect()->back();
    }

    public function generate_tasks(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'service_status_id' => 'required|exists:service_status,id',
            'is_emergency' => 'nullable|string',
        ]);

        // Generate tasks based on service status
        TaskService::createTasksForWorkOrder(
            $workOrder,
            $validated['is_emergency'] === 'Emergency',
            $validated['service_status_id']
        );

        Log::info('Tasks generated from service status: ', [
            'work_order_id' => $workOrder->id,
            'service_status_id' => $validated['service_status_id'],
            'is_emergency' => $validated['is_emergency'],
        ]);

        return redirect()->back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkOrderTask $task)
    {

        $currentTask = WorkOrderTask::with(['work_order', 'task.taskDetails', 'task.taskDetailYesOption', 'task.taskDetailNoOption'])->find($task->id);

        $task->update([
            'status' => $request->status,
            'option' => $request->option,
        ]);

        if (! $currentTask || ! $currentTask->task) {
            return redirect()->back();
        }

        $this->applyTaskCompletionEffects($currentTask, $request->option);

        Log::info('Task updated successfully: ', ['task_id' => $task->id]);

        return redirect()->back();
    }

    /**
     * Complete several selected tasks for a work order in a single action.
     *
     * Routine "Not Changed" tasks are completed first; a single status-changing
     * task is applied last because it regenerates the work order's task list, so
     * any remaining status-changing tasks in the batch can no longer apply and
     * are reported as skipped.
     */
    public function bulkComplete(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'tasks' => 'required|array|min:1',
            'tasks.*.id' => 'required|integer',
            'tasks.*.option' => 'nullable|string|in:Yes,No',
        ]);

        $routine = [];
        $statusChanging = [];
        $skipped = 0;

        foreach ($validated['tasks'] as $entry) {
            $currentTask = WorkOrderTask::with(['work_order', 'task.taskDetails', 'task.taskDetailYesOption', 'task.taskDetailNoOption'])
                ->where('work_order_id', $workOrder->id)
                ->whereKey($entry['id'])
                ->first();

            // Skip anything already completed, missing, or belonging to another
            // work order (scoped by the where above).
            if (! $currentTask || $currentTask->status === 'completed') {
                $skipped++;

                continue;
            }

            $option = $entry['option'] ?? $currentTask->option;

            // Manually-created tasks (no template) and "Not Changed" template tasks
            // are routine — they only flip status. Anything that transitions the
            // work order's service status is deferred to run last.
            if ($currentTask->task && $this->changesServiceStatus($currentTask, $option)) {
                $statusChanging[] = ['task' => $currentTask, 'option' => $option];
            } else {
                $routine[] = ['task' => $currentTask, 'option' => $option];
            }
        }

        $completed = 0;

        // Routine tasks only flip status — their transition is "Not Changed".
        foreach ($routine as $item) {
            $item['task']->update([
                'status' => 'completed',
                'option' => $item['option'],
            ]);
            $completed++;
        }

        // Apply only the first status-changing task; completing it regenerates the
        // task list, so any others in the batch are counted as skipped.
        if (! empty($statusChanging)) {
            $first = array_shift($statusChanging);
            $first['task']->update([
                'status' => 'completed',
                'option' => $first['option'],
            ]);
            $completed++;
            $this->applyTaskCompletionEffects($first['task'], $first['option']);
            $skipped += count($statusChanging);
        }

        Log::info('Bulk task completion: ', [
            'work_order_id' => $workOrder->id,
            'completed' => $completed,
            'skipped' => $skipped,
        ]);

        return response()->json([
            'completed' => $completed,
            'skipped' => $skipped,
        ], 200);
    }

    /**
     * Resolve the service status a completed task transitions the work order to,
     * using the task template (and the Yes/No option for optional tasks).
     *
     * @return array{0: int|null, 1: bool|null} [next_service_status_id, is_emergency]
     */
    private function resolveNextStatus(WorkOrderTask $currentTask, ?string $option): array
    {
        if (! empty($option)) {
            if ($option === 'Yes') {
                return [
                    $currentTask->task->taskDetailYesOption?->task_service_status_id,
                    $currentTask->task->taskDetailYesOption?->is_task_service_status_emergency,
                ];
            }

            return [
                $currentTask->task->taskDetailNoOption?->task_service_status_id,
                $currentTask->task->taskDetailNoOption?->is_task_service_status_emergency,
            ];
        }

        return [
            $currentTask->task->next_service_status_id,
            $currentTask->task->is_emergency,
        ];
    }

    /**
     * Whether completing this task (with the given option) moves the work order
     * to a different service status — i.e. it is not a "Not Changed" task.
     */
    private function changesServiceStatus(WorkOrderTask $currentTask, ?string $option): bool
    {
        [$nextServiceId] = $this->resolveNextStatus($currentTask, $option);

        $status = ServiceStatus::find($nextServiceId);

        return $status !== null && $status->name !== 'Not Changed';
    }

    /**
     * Apply the side effects of completing a task: transition the work order's
     * service status (which regenerates tasks and syncs PropertyWare) when the
     * template calls for it. Optional "Yes" tasks first clear the remaining
     * pending tasks, mirroring the manual completion flow.
     */
    private function applyTaskCompletionEffects(WorkOrderTask $currentTask, ?string $option): void
    {
        [$nextServiceId, $isEmergency] = $this->resolveNextStatus($currentTask, $option);

        if ($option === 'Yes') {
            WorkOrderTask::where('work_order_id', $currentTask->work_order->id)
                ->whereNot('status', 'completed')
                ->delete();
        }

        $this->changeTaskStatus($currentTask->work_order, $nextServiceId, $isEmergency);
    }

    private function changeTaskStatus($work_order, $next_service_id, $is_emergency)
    {
        $service_status = ServiceStatus::find($next_service_id);

        if (! $service_status) {
            return;
        }

        if ($service_status->name === 'Closed') {
            $this->closeWorkOrder($work_order, $service_status);

            return;
        }

        $statusChanged = $service_status->name !== 'Not Changed';

        if ($statusChanged) {
            $work_order->update([
                'is_emergency' => $is_emergency,
            ]);

            TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);

            if (! $work_order->isCrystalCreek()) {
                $propertyWare = new PropertyWareService;

                $propertyWare->updateServiceStatus($work_order, $service_status);
            }
        }
    }

    /**
     * Close the work order when a "Close Work Order" task is completed.
     *
     * This mirrors the manual close (status, service status and completion
     * date) and syncs the closure to PropertyWare.
     */
    private function closeWorkOrder(WorkOrder $work_order, ServiceStatus $service_status): void
    {
        $work_order->update([
            'status' => 'Closed',
            'service_status_id' => $service_status->id,
            'completed_date' => now()->toDateString(),
        ]);

        // A closed work order's checklist is finished by definition: complete
        // (never delete — the rows are the audit trail) whatever is left. Bare
        // query update so the completion cascade cannot re-enter.
        WorkOrderTask::where('work_order_id', $work_order->id)
            ->where('status', '!=', 'completed')
            ->update(['status' => 'completed']);

        if (! $work_order->isCrystalCreek()) {
            $conversation_url = route('conversation.show', $work_order->id);

            $propertyWare = new PropertyWareService;
            $propertyWare->closeWorkOrder($work_order, $conversation_url);
        }

        Log::info('Work order closed via task completion: ', ['work_order_id' => $work_order->id]);
    }

    public function undo(Request $request, WorkOrderTask $task)
    {
        $task->update([
            'status' => $request->status,
        ]);
        Log::info('Task is undoned successfully: ', ['task_id' => $task->id]);

        return redirect()->back();
    }

    public function destroy(WorkOrderTask $task)
    {
        $task->delete();

        Log::info('Task deleted successfully: ', ['task_id' => $task->id]);

        return redirect()->back();
    }
}
