<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AsanaWebhookController extends Controller
{

    public function handleWebhook(Request $request)
    {
        // Asana webhook verification for the initial setup
        if ($request->hasHeader('X-Hook-Secret')) {
            return response('', 200)->header('X-Hook-Secret', $request->header('X-Hook-Secret'));
        }

        // Parse webhook events
        $events = $request->json('events', []);

        foreach ($events as $event) {
            $action = $event['action'];
            $resourceSubtype = $event['resource']['resource_subtype'];
            $parentTaskId = $event['parent']['gid'] ?? null;

            // Log the event for debugging
             Log::info('Processing Event', ['event' => $event]);

            // Handle "task_created_by_rule" and "created_by_rule"
            if (in_array($resourceSubtype, ['task_created_by_rule', 'created_by_rule'])) {
                 Log::info('Processing new task created by rule', ['parent_task_id' => $parentTaskId]);

                if ($parentTaskId) {
                    // Process the parent task
                    $this->processNewTask($parentTaskId);
                }
            }
        }

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    /**
     * Process a new task created by an Asana rule.
     */
    private function processNewTask($taskId)
    {
        $token = env('ASANA_ACCESS_TOKEN');

        // Fetch task details
        $response = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}", [
            'opt_fields' => 'gid,name,projects,subtasks,due_on'
        ]);

        if ($response->failed()) {
            Log::error('Failed to fetch task details', ['task_id' => $taskId, 'response' => $response->body()]);
            return;
        }

        $task = $response->json()['data'] ?? null;
        if (!$task) {
            Log::warning('No task data found', ['task_id' => $taskId]);
            return;
        }

         Log::info('Processing new parent task', ['task' => $task]);

        // Update "Set Dues" subtasks (if any)
        $this->updateSetDuesSubtasks($taskId);
    }

    /**
     * Update "Set Dues" subtasks' due dates for a given task.
     */
    private function updateSetDuesSubtasks($taskId)
    {
        $token = env('ASANA_ACCESS_TOKEN');

        // Fetch subtasks for the parent task
        $subtaskResponse = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}/subtasks", [
            'opt_fields' => 'gid,name,due_on,completed'
        ]);

        if ($subtaskResponse->failed()) {
            Log::error('Failed to fetch subtasks', ['task_id' => $taskId, 'response' => $subtaskResponse->body()]);
            return;
        }

        $subtasks = $subtaskResponse->json()['data'] ?? [];

        foreach ($subtasks as $subtask) {
            // Check for "Set Dues" subtasks that are not completed
            if ((preg_match('/\bset due\b/i', $subtask['name']) || preg_match('/\bset dues\b/i', $subtask['name'])) && !$subtask['completed']) {
                $subtaskId = $subtask['gid'];
                $nextMonday = now()->next('Monday')->toDateString();

                 Log::info('Found "Set Dues" subtask', [
                    'subtask_id' => $subtaskId,
                    'current_due_date' => $subtask['due_on'],
                    'new_due_date' => $nextMonday,
                ]);

                // Update due date if it is different from the next Monday
                if ($subtask['due_on'] !== $nextMonday) {
                    $updateResponse = Http::withToken($token)->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                        'data' => [
                            'due_on' => $nextMonday
                        ]
                    ]);

                    if ($updateResponse->failed()) {
                        Log::error('Failed to update "Set Dues" subtask', [
                            'subtask_id' => $subtaskId,
                            'response' => $updateResponse->body(),
                        ]);
                    } else {
                         Log::info('Successfully updated "Set Dues" subtask', ['subtask_id' => $subtaskId]);
                    }
                }
            }
        }
    }
}