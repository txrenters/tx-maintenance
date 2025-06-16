<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLOnTheMarketJob;
use App\Jobs\ProcessNewPmLeaseOnTheMarketJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsanaWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // Handle Asana's webhook verification handshake
        if ($request->hasHeader('X-Hook-Secret')) {
            return response('', 200)->withHeaders([
                'X-Hook-Secret' => $request->header('X-Hook-Secret'),
            ]);
        }

        $data = $request->all();

        Log::info('Asana events data received:', ['events' => $data]);

        if (! empty($data['events'])) {
            foreach ($data['events'] as $event) {
                $resourceGid = $event['resource']['gid'] ?? null;

                $projectId = $event['project_id'] ?? null;

                if (! $projectId && $resourceGid) {
                    $response = Http::withToken(config('services.asana.token'))
                        ->get("https://app.asana.com/api/1.0/tasks/{$resourceGid}", [
                            'opt_fields' => 'projects',
                        ]);

                    if ($response->failed()) {
                        Log::error("Failed to fetch task {$resourceGid}", ['response' => $response->body()]);

                        continue;
                    }

                    $projects = $response['data']['projects'] ?? [];
                    $projectId = $projects[0]['gid'] ?? null;
                }

                if ($projectId == env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET')) {
                    ProcessNewPmLeaseOnTheMarketJob::dispatch();
                } elseif ($projectId == env('ASANA_PROJECT_ID_L_ON_THE_MARKET')) {
                    ProcessLOnTheMarketJob::dispatch();
                }

                Log::info('Asana webhook event received', [
                    'event' => $event,
                    'project_id' => $projectId ?? 'unknown',
                ]);

                // if ($resourceGid) {
                //     // Get the project ID from the task
                //     $response = Http::withToken(config('services.asana.token'))
                //         ->get("https://app.asana.com/api/1.0/tasks/{$resourceGid}", [
                //             'opt_fields' => 'projects',
                //         ]);

                //     if ($response->failed()) {
                //         Log::error("Failed to fetch task {$resourceGid}", ['response' => $response->body()]);
                //         continue;
                //     }

                //     $projectId = $response['data']['projects'][0]['gid'] ?? null;

                //     if ($projectId == env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET')) {
                //         ProcessNewPmLeaseOnTheMarketJob::dispatch();
                //     } elseif ($projectId == env('ASANA_PROJECT_ID_L_ON_THE_MARKET')) {
                //         ProcessLOnTheMarketJob::dispatch();
                //     }

                //     Log::info('Asana webhook event received', [
                //         'event' => $event,
                //         'project_id' => $projectId ?? 'unknown',
                //     ]);
                // }
            }
        }

        return response()->noContent();

    }
}
