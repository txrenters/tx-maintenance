<?php

namespace App\Http\Controllers;

use App\Models\TaskTemplate;
use App\Http\Requests\StoreTaskTemplateRequest;
use App\Http\Requests\UpdateTaskTemplateRequest;
use App\Models\ServiceStatus;
use Illuminate\Http\Request;

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

        TaskTemplate::create($request->all());

        return redirect()->route('task_templates.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(TaskTemplate $taskTemplate)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TaskTemplate $taskTemplate)
    {
        $statuses = ServiceStatus::all();

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

        $taskTemplate->update($request->all());

        return redirect()->route('task_templates.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaskTemplate $taskTemplate)
    {
        $taskTemplate->delete();

        return redirect()->route('task_templates.index');
    }
}
