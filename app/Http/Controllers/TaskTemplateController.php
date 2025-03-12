<?php

namespace App\Http\Controllers;

use App\Models\TaskTemplate;
use App\Http\Requests\StoreTaskTemplateRequest;
use App\Http\Requests\UpdateTaskTemplateRequest;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Gate::authorize('view_status', ServiceStatus::class);

        $perPage = $request->per_page
        ? ($request->per_page == 'All' ? TaskTemplate::count() : $request->per_page)
        : 10;

        $templates = TaskTemplate::query()
            ->with('currentServiceStatus')
            ->filter(request(['search']))
            ->orderBy('name','ASC')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($temlate) {
                return [
                    'id' => $temlate->id,
                    'name' => $temlate->name,
                    'description' => $temlate->description,
                    'current_service_status' => $temlate->currentServiceStatus->name,
                    'is_emergency' => $temlate->is_current_service_status_emergency ? 'Emergency' : 'Non-emergency',
                ];
            });

        return inertia('TaskTemplate/Index', [
            'title' => 'Task Templates',
            'templates' => $templates,
            'filter' => $request->only(['search','per_page']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $statuses = ServiceStatus::all();

        return inertia('TaskTemplate/Create', [
            'title' => 'Create Task Template',
            'statuses' => $statuses,
        ]); 
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskTemplateRequest $request)
    {
        $request->validated();

        DB::beginTransaction();

        try{

            $taskTemplate = TaskTemplate::create([
                'name' => $request->name,
                'description' => $request->description,
                'current_service_status_id' => $request->current_service_status_id,
                'is_current_service_status_emergency' => $request->is_current_service_status_emergency == 'Emergency' ? true : false,
                'next_service_status_id' => $request->next_service_status_id,
                'is_next_service_status_emergency' => $request->is_next_service_status_emergency == 'Emergency' ? true : false,
            ]);
    
            foreach($request->tasks as $task){
                $taskDate = [
                    'name' => $task['name'],
                    'is_optional' => $task['is_option'] == 'Yes' ? true : false,
                    'is_mandatory'=> $task['is_mandatory'] == 'Yes' ? true : false,
                    'type' => $task['task_for'],
                    'due_date' => $task['due_date'],
                    'next_service_status_id' => $task['task_service_status_id'],
                    'is_emergency' => $task['is_task_service_status_emergency'] == 'Emergency' ? true : false,
                    'task_template_id' => $taskTemplate->id,
                ];
    
                if (!empty($task['name'])) { // Ensure task name exists before inserting
                    $createdTask = Task::create($taskDate);
                
                    if($task['is_option'] == 'No'){ // If task option is No, no need to add task details
                        continue;
                    }
                    // Check if 'task_details' is an array before looping
                    if (!empty($task['task_details']) && is_array($task['task_details'])) {
                        foreach ($task['task_details'] as $taskDetail) {
                            TaskDetail::create([
                                'task_id' => $createdTask->id, // Corrected: Use created task ID
                                'task_for' => $taskDetail['task_for'] ?? null,
                                'task_service_status_id' => $taskDetail['task_service_status_id'] ?? null,
                                'is_task_service_status_emergency' => ($taskDetail['is_task_service_status_emergency'] ?? '') === 'Emergency',
                            ]);
                        }
                    }
                }
                
            }
            DB::commit();
            Log::info('Task template created successfully');
        }catch(\Exception $e){
            DB::rollBack();
            Log::error('Error creating task template:'.  $e->getMessage());
        }

        return redirect()->back();
    }

    /**
     * Display the specified resource.
     */
    public function show(TaskTemplate $taskTemplate)
    {
        $statuses = ServiceStatus::all();

        $taskTemplate = TaskTemplate::with(['currentServiceStatus','nextServiceStatus','tasks.taskDetails.taskServiceStatus','tasks.nextServiceStatus'])->find($taskTemplate->id);

        // dd($taskTemplate);
        return inertia('TaskTemplate/Show', [
            'title' => 'Task Template',
            'template' => $taskTemplate,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TaskTemplate $taskTemplate)
    {
        $statuses = ServiceStatus::all();

        $taskTemplate = TaskTemplate::with('tasks.taskDetails')->find($taskTemplate->id);

        return inertia('TaskTemplate/Edit', [
            'title' => 'Edit Task Template',
            'template' => $taskTemplate,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTaskTemplateRequest $request, TaskTemplate $taskTemplate)
    {
        $request->validated();
        DB::beginTransaction();
        try{

           $taskTemplate->update([
                'name' => $request->name,
                'description' => $request->description,
                'current_service_status_id' => $request->current_service_status_id,
                'is_current_service_status_emergency' => $request->is_current_service_status_emergency == 'Emergency' ? true : false,
                'next_service_status_id' => $request->next_service_status_id,
                'is_next_service_status_emergency' => $request->is_next_service_status_emergency == 'Emergency' ? true : false,
            ]);

            Task::where('task_template_id', $taskTemplate->id)->delete();

            foreach($request->tasks as $task){
                $taskData = [
                    'name' => $task['name'],
                    'is_optional' => $task['is_option'] == 'Yes' ? true : false,
                    'is_mandatory'=> $task['is_mandatory'] == 'Yes' ? true : false,
                    'type' => $task['task_for'],
                    'due_date' => $task['due_date'],
                    'next_service_status_id' => $task['task_service_status_id'] ?? null,
                    'is_emergency' => $task['is_task_service_status_emergency'] == 'Emergency' ? true : false,
                    'task_template_id' => $taskTemplate->id,
                ];
    
                if (!empty($task['name'])) { 

                    $taskId = $task['id'] ?? null;
                    $taskModel = null; // Correctly initialize as null
                    
                    if ($taskId) {
                        $taskModel = Task::find($taskId);
                        if ($taskModel) {
                            $taskModel->update($taskData);
                        }
                    } 
                
                    // If taskModel is still null, create a new task
                    if (!$taskModel) {
                        $taskModel = Task::create($taskData);
                    }

                    if (!empty($task['task_details']) && is_array($task['task_details'])) {

                        TaskDetail::where('task_id', $taskModel->id)->delete();

                        if($task['is_option'] == 'No'){ // If task option is No, no need to add task details
                            continue;
                        }
                        foreach ($task['task_details'] as $taskDetail) {
                            TaskDetail::create([
                                'task_id' => $taskModel->id, // Corrected: Use created task ID
                                'task_for' => $taskDetail['task_for'] ,
                                'task_service_status_id' => $taskDetail['task_service_status_id'],
                                'is_task_service_status_emergency' => ($taskDetail['is_task_service_status_emergency'] ?? '') === 'Emergency',
                            ]);
                        }
                    }
                }
                
            }
            Log::info('Task template updated successfully');
            DB::commit();
        }catch(\Exception $e){
            DB::rollBack();
            Log::error('Error editing task template: '.  $e->getMessage());
        }
        return redirect()->back();

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaskTemplate $taskTemplate)
    {
        $taskTemplate->delete();

        return redirect()->back();
    }
}
