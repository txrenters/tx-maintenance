<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

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

        $event = $request->all();

        $data = $request->all();

        if (! empty($data['events'])) {
            foreach ($data['events'] as $event) {
                $resourceGid = $event['resource']['gid'] ?? null;

                if ($resourceGid) {
                    // Get the project ID from the task
                    $response = Http::withToken(config('services.asana.token'))
                        ->get("https://app.asana.com/api/1.0/tasks/{$resourceGid}", [
                            'opt_fields' => 'projects',
                        ]);

                    $projectId = $response['data']['projects'][0]['gid'] ?? null;

                    if ($projectId === env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET')) {
                        Artisan::call('asana:process-new-pm-lease-on-the-market');
                    } elseif ($projectId === env('ASANA_PROJECT_ID_L_ON_THE_MARKET')) {
                        Artisan::call('asana:process-new-pm-lease-on-the-market');
                    }
                }
            }
        }

        return response()->noContent();

    }
}
