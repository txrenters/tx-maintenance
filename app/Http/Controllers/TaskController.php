<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        ])
            ->when($request->search, function ($query) use ($request) {
                $query->where('work_order_no', 'like', '%'.$request->search.'%');
            })
            ->whereHas('tasks', function ($query) {
                $query->whereNotNull('work_order_id'); // Ensure tasks are linked to a work order
            })->get();

        $now = now();

        $dueTodayTasks = $workOrders->flatMap(function ($workOrder) {
            return $workOrder->tasks->filter(function ($task) {
                return $task->status == 'pending' && Carbon::parse($task->due_date)->isToday();
            })->map(function ($task) use ($workOrder) {
                $task->work_order_no = $workOrder->work_order_no; // Add work_order_id to the task

                return $task;
            });
        });

        $upcomingTasks = $workOrders->flatMap(function ($workOrder) use ($now) {
            return $workOrder->tasks->filter(function ($task) use ($now) {
                return $task->status == 'pending' && Carbon::parse($task->due_date)->isAfter($now);
            })->map(function ($task) use ($workOrder) {
                $task->work_order_no = $workOrder->work_order_no; // Add work_order_id to the task

                return $task;
            });
        });

        $pastDueTasks = $workOrders->flatMap(function ($workOrder) use ($now) {
            return $workOrder->tasks->filter(function ($task) use ($now) {
                return $task->status == 'pending' && Carbon::parse($task->due_date)->toDateString() < $now->toDateString();

            })->map(function ($task) use ($workOrder) {
                $task->work_order_no = $workOrder->work_order_no; // Add work_order_id to the task

                return $task;
            });
        });

        $completeTasks = $workOrders->flatMap(function ($workOrder) {
            return $workOrder->tasks->filter(function ($task) {
                return $task->status == 'completed';
            })->map(function ($task) use ($workOrder) {
                $task->work_order_no = $workOrder->work_order_no; // Add work_order_id to the task

                return $task;
            });
        });

        return inertia('Task/Index', [
            'title' => 'Work Order Task',
            'total_dueTodayTasks' => count($dueTodayTasks),
            'total_upcomingTasks' => count($upcomingTasks),
            'total_pastDueTasks' => count($pastDueTasks),
            'total_completedTasks' => count($completeTasks),
            'dueTodayTasks' => Inertia::optional(function () use ($dueTodayTasks) {
                return $dueTodayTasks;
            }),            
            'upcomingTasks' => Inertia::optional(function () use ($upcomingTasks) {
                return $upcomingTasks;
            }),
            'pastDueTasks' => Inertia::optional(function () use ($pastDueTasks) {
                return $pastDueTasks;
            }),
            'completedTasks' => Inertia::optional(function () use ($completeTasks) {
                return $completeTasks;
            }),
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
