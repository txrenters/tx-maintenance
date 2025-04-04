<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $workOrders = WorkOrder::with([
            'tasks.task.taskDetails.taskServiceStatus',
            'tasks.task.nextServiceStatus',
            'tasks.assigned_user',
        ])->whereHas('tasks', function ($query) {
            $query->whereNotNull('work_order_id'); // Ensure tasks are linked to a work order
        })->get();

        $tasks = $workOrders->flatMap(function ($workOrder) {
            return $workOrder->tasks->map(function ($task) use ($workOrder) {
                $task->work_order_no = $workOrder->work_order_no; // Add work_order_id to the task

                return $task;
            });
        });

        return inertia('Task/Index', [
            'title' => 'Work Order Task',
            'tasks' => $tasks,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string',
            'due_date' => 'required|date',
            'assigned_user_id' => 'required|exists:users,id',
            'work_order_id' => 'required|exists:work_orders,id',
        ]);

        WorkOrderTask::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, WorkOrderTask $task)
    {
        $task->update([
            'description' => $request->description,
            'due_date' => $request->due_date,
        ]);

        return redirect()->back();
    }

    /**
     * Display the specified resource.
     */
    public function tasks(WorkOrder $workOrder)
    {
        $workOrder->load(['tasks.task.taskDetails.taskServiceStatus', 'tasks.task.nextServiceStatus', 'tasks.assigned_user']);

        return response()->json($workOrder, 200);
    }

    public function service_status_change(Request $request, WorkOrder $workOrder)
    {
        if ($request->service_status_id == 1) {
            $workOrder->update([
                'is_emergency' => null,
                'local_status' => 'Created',
            ]);
        }
        WorkOrderTask::where('work_order_id', $workOrder->id)->where('status', '!=', 'completed')->delete();

        TaskService::createTasksForWorkOrder($workOrder, $request->is_emergency == 'Emergency', $request->service_status_id);

        return response()->json(['message' => 'Created successfully!'], 200);

    }
}
