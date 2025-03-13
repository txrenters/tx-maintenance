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
        if (!$workOrder) {
            return response()->json(['message' => 'Work Order not found'], 404);
        }

        $workOrder->load(['tasks.task.taskDetails','tasks.assigned_user']);
    
        return response()->json($workOrder, 200);
    }

    public function task_change(Request $request, WorkOrderTask $task)
    {        
        if (!$task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $currentTask = WorkOrderTask::find($task->id);

        DB::beginTransaction();
        try{

            $currentTask->update(['status' => $request->status]);

            $work_order = $currentTask->work_order;
            // check if there are incomplete task
            $allCompleted = !WorkOrderTask::where('work_order_id', $work_order->id)->where('status','!=','completed')->exists();
    
            if ($allCompleted) {
                $next_service_id = $currentTask->task->taskTemplate->next_service_status_id ; //get the task template to get the next service status
                $is_emergency = $currentTask->task->taskTemplate->is_next_service_status_emergency; 
    
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
    
                            // if task is for work order coodinator, assigned a task to it
                            if($task->type == 'Woc'){
                                $assigned_user_id = User::role('woc')->first();
                            
                                $tasks[] = [
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
    
                            $work_order = WorkOrder::find($work_order->id);
    
                            $change_service_status = $propertywareServices->changeServiceStatusPropertyWare($work_order, $task_template->nextServiceStatus);
                            
                            if($change_service_status){
                                $work_order->update([
                                    'service_status_id' => $task_template->nextServiceStatus->id
                                ]);
                            }
                        }
                    }
    
            }
        
            Log::info('Task updated successfully task: ', [ 'task_id' => $task->id]);
            DB::commit();
            return response()->json($task, 200);

        }catch (Exception $e){
            Log::error('Error updating task: '. $e->getMessage());
            DB::rollBack();
            return response()->json(['message' => 'Error updating task'], 404);

        }
    }
}
