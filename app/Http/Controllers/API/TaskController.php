<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    public function tasks(WorkOrder $workOrder)
    {
        $workOrder->load(['tasks.task.taskDetails.taskServiceStatus','tasks.task.nextServiceStatus','tasks.assigned_user']);
    
        return response()->json($workOrder, 200);
    }

    public function service_status_change(Request $request, WorkOrder $workOrder)
    {
        if($request->service_status_id == 1){
            $workOrder->update([
                'is_emergency' => null,
                'local_status' => 'Created'
            ]);
            WorkOrderTask::where('work_order_id',$workOrder->id)->delete();

        }else{
            TaskService::createTasksForWorkOrder($workOrder, $request->is_emergency == 'Emergency', $request->service_status_id);
            WorkOrderTask::where('work_order_id',$workOrder->id)->where('status', '!=', 'completed')->delete();
        }
        return redirect()->back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkOrderTask $task)
    {
       
        
        $task->update([
            'status' => $request->status,
            'option' => $request->option
        ]);

        $currentTask = WorkOrderTask::with(['work_order','task.taskDetails','task.taskDetailYesOption'])->find($task->id);

        if (!$currentTask || !$currentTask->task) {
            return redirect()->back();
        }

        $work_order = $currentTask->work_order;

        // check if there are incomplete task
        $allCompleted = !WorkOrderTask::where('work_order_id', $work_order->id)
            ->where('status', '!=', 'completed')
            ->exists();

        // Ensure $currentTask is valid
        

        if ($allCompleted) {
            $next_service_id = $currentTask->task->taskTemplate?->next_service_status_id;
            $is_emergency = $currentTask->task->taskTemplate?->is_next_service_status_emergency;

            $service_status = ServiceStatus::find($next_service_id);

            if($service_status->name == 'Not Change' || $service_status->name == 'Closed' ){
                $next_service_id = $currentTask->task->taskTemplate->current_service_status_id; //ensure it will not go to the next service
            }else{
                $propertyWare = new PropertyWareService();
                $propertyWare->changeServiceStatusPropertyWare($currentTask->work_order, $service_status);

                TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);

            }

        } else if (!$allCompleted && $request->status == 'completed' && !empty($request->option)) {

            if($request->option == 'Yes'){
                $next_service_id = $currentTask->task->taskDetailYesOption?->task_service_status_id;
                $is_emergency = $currentTask->task->taskDetailYesOption?->is_task_service_status_emergency;
            }else{
                $next_service_id = $currentTask->task->taskDetailNoOption?->task_service_status_id;
                $is_emergency = $currentTask->task->taskDetailNoOption?->is_task_service_status_emergency;
            }

            $service_status = ServiceStatus::find($next_service_id);

            if($service_status->name == 'Not Change' || $service_status->name == 'Closed' ){
                $next_service_id = $currentTask->task->taskTemplate->next_service_status_id; //ensure it will not go to the next service
            }else{
                $propertyWare = new PropertyWareService();
                $propertyWare->changeServiceStatusPropertyWare($currentTask->work_order, $service_status);

                WorkOrderTask::where('work_order_id',$work_order->id)->where('status', '!=', 'completed')->delete();
                TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);

            }

        }else{
            $next_service_id = $currentTask->task->next_service_status_id;
            $is_emergency = $currentTask->task->is_emergency;

            $service_status = ServiceStatus::find($next_service_id);

            if($service_status->name == 'Not Change' || $service_status->name == 'Closed' ){
                $next_service_id = $currentTask->task->taskTemplate->next_service_status_id; //ensure it will not go to the next service
            }else{
                $propertyWare = new PropertyWareService();
                $propertyWare->changeServiceStatusPropertyWare($currentTask->work_order, $service_status);

                TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);

            }
        }

        Log::info('Task updated successfully: ', [ 'task_id' => $task->id]);
        
        return redirect()->back();
    }
}
