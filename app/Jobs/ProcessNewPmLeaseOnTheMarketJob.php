<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessNewPmLeaseOnTheMarketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        Log::info('▶️ Processing NEW PM LEASE ON THE MARKET APM rules (via Job)...');

        $token = config('services.asana.token');
        $projectId = env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET');

        $monday = Carbon::parse('next monday')->format('Y-m-d');
        $tuesday = Carbon::parse('next tuesday')->format('Y-m-d');
        $wednesday = Carbon::parse('next wednesday')->format('Y-m-d');

        $reviewCount = 0;

        $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/tasks', [
            'project' => $projectId,
            'opt_fields' => 'gid,name,completed',
        ]);

        if ($response->failed()) {
            Log::error('❌ Failed to fetch tasks', ['project_id' => $projectId]);

            return;
        }

        $tasks = $response->json()['data'] ?? [];

        foreach ($tasks as $task) {
            $taskId = $task['gid'];

            $subtaskResponse = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}/subtasks", [
                'opt_fields' => 'gid,name,due_on,completed',
            ]);

            if ($subtaskResponse->failed()) {
                Log::error('❌ Failed to fetch subtasks', ['task_id' => $taskId]);

                continue;
            }

            $subtasks = $subtaskResponse->json()['data'] ?? [];

            foreach ($subtasks as $subtask) {
                if ($subtask['completed']) {
                    continue;
                }

                $name = strtolower($subtask['name']);
                $subtaskId = $subtask['gid'];
                $oldDue = $subtask['due_on'];

                // Skip if already has due date
                if (! empty($oldDue)) {
                    continue;
                }

                // 🧠 Set new due date
                if (str_contains($name, 'update owner')) {
                    $newDue = $wednesday;
                } elseif (str_contains($name, 'review recommended action') && $reviewCount < 2) {
                    $newDue = $tuesday;
                    $reviewCount++;
                } else {
                    $newDue = $monday;
                }

                Log::info("🔄 Updating subtask: {$subtask['name']}", [
                    'old_due_on' => $oldDue,
                    'new_due_on' => $newDue,
                ]);

                $update = Http::withToken($token)->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                    'data' => ['due_on' => $newDue],
                ]);

                if ($update->failed()) {
                    Log::error('❌ Failed to update subtask', [
                        'subtask' => $subtask['name'],
                        'response' => $update->body(),
                    ]);
                } else {
                    Log::info('✅ Subtask updated', ['name' => $subtask['name']]);
                }

            }
        }

        Log::info('✅ Done updating tasks (Job finished).');
    }
}
