<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Http\Requests\StoreTaskRequest;
use App\Models\ServiceStatus;
use App\Models\TaskDetail;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tasks = WorkOrderTask::with(['assigned_user','task.taskTemplate'])->get();

        return inertia('Task/Index', [
            'title' => 'Work Order Task',
            'tasks' => $tasks,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
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
        }
        WorkOrderTask::where('work_order_id',$workOrder->id)->where('status', '!=', 'completed')->delete();

        TaskService::createTasksForWorkOrder($workOrder, $request->is_emergency == 'Emergency', $request->service_status_id);

        return response()->json(['message' => 'Created successfully!'], 200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WorkOrderTask $task)
    {
        $currentTask = WorkOrderTask::with('task.taskDetails','task.taskDetailYesOption')->find($task->id);
        
        $option =  $request->option;
        $task->update([
            'status' => $request->status,
            'option' => $option
        ]);

        $work_order = $currentTask->work_order;

        // check if there are incomplete task
        $allCompleted = !WorkOrderTask::where('work_order_id', $work_order->id)
            ->where('status', '!=', 'completed')
            ->exists();

        // Ensure $currentTask is valid
        if (!$currentTask || !$currentTask->task) {
            return response()->json(['error' => 'Invalid task data'], 400);
        }

        if ($allCompleted) {
            $next_service_id = $currentTask->task->taskTemplate?->next_service_status_id;
            $is_emergency = $currentTask->task->taskTemplate?->is_next_service_status_emergency;

            $service_status = ServiceStatus::find($next_service_id);

            if($service_status->name == 'Not Change'){
                $next_service_id = $currentTask->task->taskTemplate->current_service_status_id; //ensure it will not go to the next service
            }

            if ($next_service_id) {
                WorkOrderTask::where('work_order_id',$work_order->id)->where('status', '!=', 'completed')->delete();
                TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);
            }

        } else if (!$allCompleted && $request->status == 'completed' && !empty($request->option)) {

            if($request->option == 'Yes'){
                $next_service_id = $currentTask->task->taskDetailYesOption?->task_service_status_id;
            }else{
                $next_service_id = $currentTask->task->taskDetailNoOption?->task_service_status_id;
            }

            $is_emergency = $currentTask->task->taskDetailYesOption?->is_task_service_status_emergency;

            $service_status = ServiceStatus::find($next_service_id);

            if($service_status->name == 'Not Change'){
                $next_service_id = $currentTask->task->taskTemplate->next_service_status_id; //ensure it will not go to the next service
            }else{
                WorkOrderTask::where('work_order_id',$work_order->id)->where('status', '!=', 'completed')->delete();
            }

            if ($next_service_id) {
                TaskService::createTasksForWorkOrder($work_order, $is_emergency, $next_service_id);
            }
        }

        Log::info('Task updated successfully: ', [ 'task_id' => $task->id]);
        return response()->json($task, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Task $task)
    {
        //
    }
}
