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

        $propertyWare = new PropertyWareService;
        $propertyWare->updateServiceStatus($workOrder, $service_status);

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

        $work_order = $currentTask->work_order;

        if (! empty($request->option)) {

            if ($request->option == 'Yes') {
                $next_service_id = $currentTask->task->taskDetailYesOption?->task_service_status_id;
                $is_emergency = $currentTask->task->taskDetailYesOption?->is_task_service_status_emergency;
            } else {
                $next_service_id = $currentTask->task->taskDetailNoOption?->task_service_status_id;
                $is_emergency = $currentTask->task->taskDetailNoOption?->is_task_service_status_emergency;
            }

        } else {
            $next_service_id = $currentTask->task->next_service_status_id;
            $is_emergency = $currentTask->task->is_emergency;
        }

        $service_status = ServiceStatus::find($next_service_id);

        if ($service_status->name != 'Not Changed' && $service_status->name != 'Closed') {
            $propertyWare = new PropertyWareService;
            $propertyWare->changeServiceStatusPropertyWare($currentTask->work_order, $service_status);

            $work_order->update([   // modify work order emergency base on task
                'is_emergency' => $is_emergency,
            ]);

            WorkOrderTask::where('work_order_id', $work_order->id)->where('status', '!=', 'completed')->delete();
            TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);

            $propertyWare = new PropertyWareService;

            $propertyWare->updateServiceStatus($work_order, $service_status);

        }

        Log::info('Task updated successfully: ', ['task_id' => $task->id]);

        return redirect()->back();
    }

    public function undo(Request $request, WorkOrderTask $task)
    {
        $task->update([
            'status' => $request->status,
        ]);
        Log::info('Task is undoned successfully: ', ['task_id' => $task->id]);
        return redirect()->back();
    }
}
