<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UpdateTaskDueDate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asana:set-dues';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and update tasks in Asana';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Starting update for asana task command...');

        $token = config('services.asana.token'); // Get API token from config
        $projectIds = [env('ASANA_PROJECT_ID_L_ON_THE_MARKET'), env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET')]; // Replace with actual Asana project GIDs

        foreach ($projectIds as $projectId) {
            Log::info("Fetching tasks from project: {$projectId}");

            // ✅ Step 1: Fetch all tasks in the project
            $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/tasks', [
                'project' => $projectId,
                'opt_fields' => 'gid,name,completed',
            ]);

            if ($response->failed()) {
                Log::error('Failed to fetch tasks', ['project_id' => $projectId, 'response' => $response->body()]);

                continue;
            }

            $tasks = $response->json()['data'] ?? [];

            foreach ($tasks as $task) {
                $taskId = $task['gid'];

                // ✅ Step 2: Fetch subtasks for each task
                $subtaskResponse = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}/subtasks", [
                    'opt_fields' => 'gid,name,due_on,completed',
                ]);

                if ($subtaskResponse->failed()) {
                    Log::error('Failed to fetch subtasks'.json_encode(['task_id' => $taskId, 'response' => $subtaskResponse->body()]));

                    continue;
                }

                $subtasks = $subtaskResponse->json()['data'] ?? [];

                foreach ($subtasks as $subtask) {

                    if ($subtask['completed']) {
                        continue;
                    }

                    $subtaskId = $subtask['gid'];
                    $subtaskName = $subtask['name'];

                    $nextMonday = Carbon::now()->next(Carbon::MONDAY)->toDateString();
                    $nextTuesday = Carbon::now()->next(Carbon::TUESDAY)->toDateString();

                    if ((preg_match('/\bUpdate owner\b/i', $subtask['name']) || preg_match('/\bRECOMMENDED\b/i', $subtask['name']))) {

                        Log::info('Subtask:', [
                            'subtask_name' => $subtaskName,
                            'due_date' => $subtask['due_on'],
                        ]);

                        if (empty($subtask['due_on'])) {
                            Log::info('Updating subtask:', [
                                'subtask_name' => $subtaskName,
                                'new_due_date' => $nextTuesday,
                            ]);

                            $updateResponse = Http::withToken($token)
                                ->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                                    'data' => ['due_on' => $nextTuesday],
                                ]);

                            if ($updateResponse->failed()) {
                                Log::error('Failed to update subtask', [
                                    'subtask_name' => $subtaskName,
                                    'response' => $updateResponse->body(),
                                ]);
                            } else {
                                Log::info('Successfully updated the subtask:', ['subtask_name' => $subtaskName]);
                            }
                        }
                    }else{

                        Log::info('Subtask:', [
                            'subtask_name' => $subtaskName,
                            'due_date' => $subtask['due_on'],
                        ]);

                        if (empty($subtask['due_on'])) {
                            Log::info('Updating subtask:', [
                                'subtask_name' => $subtaskName,
                                'new_due_date' => $nextMonday,
                            ]);

                            $updateResponse = Http::withToken($token)
                                ->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                                    'data' => ['due_on' => $nextMonday],
                                ]);

                            if ($updateResponse->failed()) {
                                Log::error('Failed to update subtask', [
                                    'subtask_name' => $subtaskName,
                                    'response' => $updateResponse->body(),
                                ]);
                            } else {
                                Log::info('Successfully updated the subtask:', ['subtask_name' => $subtaskName]);
                            }
                        }
                    }
                }
            }
        }

        Log::info('Update Asana Tasks command completed.');
    }
}
