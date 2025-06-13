<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessNewPmLeaseOnTheMarket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asana:process-new-pm-lease-on-the-market';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update due dates for NEW PM LEASE ON THE MARKET project subtasks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('🔁 Starting update for Asana L on the Market...');

        $token = config('services.asana.token');
        $projectId = env('ASANA_PROJECT_ID_L_ON_THE_MARKET');

        $monday = Carbon::now()->next(Carbon::MONDAY)->toDateString();
        $thursday = Carbon::now()->next(Carbon::THURSDAY)->toDateString();

        $mondayKeywords = ['FILL OUT', 'ARE THERE', 'HOW MANY', 'CMA LINK', 'SET DUE DATE', 'SET DUES DATE'];

        Log::info("📁 Fetching tasks from project: {$projectId}");

        $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/tasks', [
            'project' => $projectId,
            'opt_fields' => 'gid,name,completed',
        ]);

        if ($response->failed()) {
            Log::error('❌ Failed to fetch tasks', ['project_id' => $projectId, 'response' => $response->body()]);

            return;
        }

        $tasks = $response->json()['data'] ?? [];

        foreach ($tasks as $task) {
            $taskId = $task['gid'];

            $subtaskResponse = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}/subtasks", [
                'opt_fields' => 'gid,name,due_on,completed',
            ]);

            if ($subtaskResponse->failed()) {
                Log::error('❌ Failed to fetch subtasks', ['task_id' => $taskId, 'response' => $subtaskResponse->body()]);

                continue;
            }

            $subtasks = $subtaskResponse->json()['data'] ?? [];

            // Check if "Update owner" is completed
            $ownerUpdateComplete = collect($subtasks)->contains(function ($sub) {
                return Str::contains(strtolower($sub['name']), 'Update owner') && $sub['completed'];
            });

            foreach ($subtasks as $subtask) {
                if ($subtask['completed']) {
                    continue;
                }

                $subtaskId = $subtask['gid'];
                $subtaskName = $subtask['name'];
                $oldDue = $subtask['due_on'];

                if (
                    ! Str::contains(strtoupper($subtaskName), $mondayKeywords) &&
                    ! Str::contains(strtolower($subtaskName), 'Update owner')
                ) {
                    continue;
                }

                if (Str::contains(strtolower($subtaskName), 'Update owner')) {
                    $newDue = $thursday;
                } elseif ($ownerUpdateComplete && Str::contains(strtolower($subtaskName), 'set due')) {
                    $newDue = $monday;
                } else {
                    $newDue = $monday;
                }

                if ($oldDue !== $newDue) {
                    Log::info("📅 Updating subtask: {$subtaskName}", [
                        'old_due' => $oldDue,
                        'new_due' => $newDue,
                    ]);

                    $updateResponse = Http::withToken($token)->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                        'data' => ['due_on' => $newDue],
                    ]);

                    if ($updateResponse->failed()) {
                        Log::error('❌ Failed to update subtask', [
                            'subtask_name' => $subtaskName,
                            'response' => $updateResponse->body(),
                        ]);
                    } else {
                        Log::info('✅ Subtask updated', ['subtask_name' => $subtaskName]);
                    }
                }
            }
        }

        Log::info('✅ Completed update for L on the Market.');
    }
}
