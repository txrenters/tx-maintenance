<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Fetch and update "Set Dues" tasks in Asana';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Starting UpdateSetDuesTasks command...');

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
                    if ((preg_match('/\bset due\b/i', $subtask['name']) || preg_match('/\bset dues\b/i', $subtask['name'])) && ! $subtask['completed']) {
                        $subtaskId = $subtask['gid'];
                        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->toDateString();

                        Log::info('"Set Dues" subtask:', [
                            'subtask_id' => $subtaskId,
                            'due_date' => $subtask['due_on'],
                        ]);

                        // ✅ Update "Set Dues" subtask if due date is different
                        if ($subtask['due_on'] != $nextMonday) {
                            Log::info('Updating "Set Dues" subtask:', [
                                'subtask_id' => $subtaskId,
                                'new_due_date' => $nextMonday,
                            ]);

                            $updateResponse = Http::withToken($token)
                                ->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                                    'data' => [
                                        'due_on' => $nextMonday,
                                    ],
                                ]);

                            if ($updateResponse->failed()) {
                                Log::error('Failed to update "Set Dues" subtask'.json_encode([
                                    'subtask_id' => $subtaskId,
                                    'response' => $updateResponse->body(),
                                ]));
                            } else {
                                Log::info('Successfully updated "Set Dues" subtask:',['subtask_id' => $subtaskId]);
                            }
                        }
                    }
                }
            }
        }

        Log::info('UpdateSetDuesTasks command completed.');
    }
}
