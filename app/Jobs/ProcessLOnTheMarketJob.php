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
use Illuminate\Support\Str;

class ProcessLOnTheMarketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        Log::info('🔁 Starting update for Asana L on the Market (via Job)...');

        $token = config('services.asana.token');
        $projectId = env('ASANA_PROJECT_ID_L_ON_THE_MARKET');

        $monday = Carbon::now()->next(Carbon::MONDAY)->toDateString();
        $thursday = Carbon::now()->next(Carbon::THURSDAY)->toDateString();

        $mondayKeywords = ['FILL OUT', 'ARE THERE', 'HOW MANY', 'CMA LINK', 'SET DUE DATE', 'SET DUES DATE'];

        $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/tasks', [
            'project' => $projectId,
            'opt_fields' => 'gid,name,completed',
        ]);

        if ($response->failed()) {
            Log::error('❌ Failed to fetch tasks for L on the Market', ['project_id' => $projectId, 'response' => $response->body()]);

            return;
        }

        $tasks = $response->json()['data'] ?? [];

        foreach ($tasks as $task) {
            $taskId = $task['gid'];

            $subtaskResponse = Http::withToken($token)->get("https://app.asana.com/api/1.0/tasks/{$taskId}/subtasks", [
                'opt_fields' => 'gid,name,due_on,completed',
            ]);

            if ($subtaskResponse->failed()) {
                Log::error('❌ Failed to fetch subtasks for L on the Market', ['task_id' => $taskId, 'response' => $subtaskResponse->body()]);

                continue;
            }

            $subtasks = $subtaskResponse->json()['data'] ?? [];

            $ownerUpdateComplete = collect($subtasks)->contains(function ($sub) {
                return Str::contains(strtolower($sub['name']), 'update owner') && $sub['completed'];
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
                    ! Str::contains(strtolower($subtaskName), 'update owner')
                ) {
                    continue;
                }

                $newDue = Str::contains(strtolower($subtaskName), 'update owner')
                    ? $thursday
                    : ($ownerUpdateComplete && Str::contains(strtolower($subtaskName), 'set due') ? $monday : $monday);

                if ($oldDue !== $newDue) {
                    Log::info("📅 Updating subtask: {$subtaskName}", [
                        'old_due' => $oldDue,
                        'new_due' => $newDue,
                    ]);

                    $updateResponse = Http::withToken($token)->put("https://app.asana.com/api/1.0/tasks/{$subtaskId}", [
                        'data' => ['due_on' => $newDue],
                    ]);

                    if ($updateResponse->failed()) {
                        Log::error('❌ Failed to update subtask for L on the Market', [
                            'subtask_name' => $subtaskName,
                            'response' => $updateResponse->body(),
                        ]);
                    } else {
                        Log::info('✅ Subtask updated for L on the Market', ['subtask_name' => $subtaskName]);
                    }
                }
            }
        }

        Log::info('✅ Completed update for L on the Market (via Job).');
    }
}
