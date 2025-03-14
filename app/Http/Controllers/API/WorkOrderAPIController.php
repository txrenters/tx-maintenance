<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderAPIController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function tasks(WorkOrder $workOrder)
    {
        $workOrder->load(['tasks.task.taskDetails.taskServiceStatus','tasks.task.nextServiceStatus','tasks.assigned_user']);
    
        return response()->json($workOrder, 200);
    }

    public function task_change(Request $request, WorkOrderTask $task)
    {        
        $currentTask = WorkOrderTask::with('task.taskDetails','task.taskDetailYesOption')->find($task->id);
        
        $option =  $option ?? 'No';
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

        if ($allCompleted && $option == 'No') {
            $next_service_id = $currentTask->task->taskTemplate?->next_service_status_id;
            $is_emergency = $currentTask->task->taskTemplate?->is_next_service_status_emergency;

            if ($next_service_id) {
                $this->create_task($work_order, $next_service_id, $is_emergency);
            }
        } else if ($option == 'Yes' && $request->status == 'completed') {
            $next_service_id = $currentTask->task->taskDetailYesOption?->task_service_status_id;
            $is_emergency = $currentTask->task->taskDetailYesOption?->is_task_service_status_emergency;

            if ($next_service_id) {
                $this->create_task($work_order, $next_service_id, $is_emergency);
            }
        }

        Log::info('Task updated successfully: ', [ 'task_id' => $task->id]);
        DB::commit();
        return response()->json($task, 200);
    }

    public function service_status_change(Request $request, WorkOrder $workOrder)
    {
        WorkOrderTask::where('work_order_id',$workOrder->id)->where('status', '!=', 'completed')->delete();

        $taskCreated = $this->create_task($workOrder, $request->service_status_id, $request->is_emergency == 'Emergency');

        if($taskCreated){
            return response()->json(['message' => 'Created successfully!'], 200);
        }
        return response()->json(['error' => 'Task creation failed!'], 500);

    }


    private function create_task($work_order, $next_service_id, $is_emergency)
    {
        WorkOrderTask::where('work_order_id',$work_order->id)->where('status', '!=', 'completed')->delete();

        $task_template = TaskTemplate::with(['currentServiceStatus','tasks']) //get all tasks
            ->whereHas('currentServiceStatus', function($q) use ($next_service_id){
                $q->where('id', $next_service_id);
            })
            ->where('is_current_service_status_emergency', $is_emergency)
            ->first();

        if(!empty($task_template->tasks)){
            $now = now();
            $tasks = [];

            foreach($task_template->tasks as $task){
                $task_due_date = $now; // Default to today
                if ($task->due_date === 'same day') {
                    // Do nothing, $task_due_date is already today
                } else {
                    // Extract numeric value from string like "1 day", "2 days"
                    preg_match('/\d+/', $task->due_date, $matches);
                    
                    if (!empty($matches)) {
                        $days = (int) $matches[0]; // Convert extracted number to integer
                        $task_due_date = $task_due_date->addDays($days);
                    }
                }

                // if task is for work order coodinator, assigned a task to it
                if($task->type == 'Woc'){
                    $assigned_user_id = User::role('woc')->first();
                    $tasks[] = [
                        'due_date' => $task_due_date,
                        'work_order_id' => $work_order->id,
                        'assigned_user_id' => $assigned_user_id->id,
                        'task_id' => $task->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }else{
                    $work_order = WorkOrder::with('vendors')->find($work_order->id);
                    if($work_order->vendors){
                        foreach($work_order->vendors as $vendor){

                            $assigned_user_id = User::with('vendor')
                                ->whereHas('vendor', function($q) use ($vendor){
                                    $q->where('id', 'LIKE', $vendor->id);
                                })
                                ->role('vendor')
                                ->first();

                            $tasks[] = [
                                'due_date' => $task_due_date,
                                'work_order_id' => $work_order->id,
                                'assigned_user_id' => $assigned_user_id->id,
                                'task_id' => $task->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                  
                }
            }

            if(!empty($tasks)){
                DB::table('work_order_tasks')->insert($tasks);
                $propertywareServices = new PropertyWareService();
                $change_service_status = $propertywareServices->changeServiceStatusPropertyWare($work_order, $task_template->currentServiceStatus);
                
                if($change_service_status){
                    $work_order->update([
                        'service_status_id' => $next_service_id
                    ]);
                }
            }


            return true;
        }

        return false;

    }
}
