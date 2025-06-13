<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessLOnTheMarket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-l-on-the-market';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update due dates for L ON THE MARKET project';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('▶️ Processing NEW PM LEASE ON THE MARKET APM rules...');

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

                // 🧠 Determine due date based on name and count
                if (str_contains($name, 'Update owner')) {
                    $newDue = $wednesday;
                } elseif (str_contains($name, 'Review Recommended Action') && $reviewCount < 2) {
                    $newDue = $tuesday;
                    $reviewCount++;
                } else {
                    $newDue = $monday;
                }

                // 🔁 Only update if date is different
                if ($oldDue !== $newDue) {
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
        }

        Log::info('✅ Done updating NEW PM LEASE ON THE MARKET APM tasks.');
    }
}
