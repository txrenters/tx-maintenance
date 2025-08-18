<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLOnTheMarketJob;
use App\Jobs\ProcessNewPmLeaseOnTheMarketJob;
use Illuminate\Http\Request;
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

        // if (! empty($data['events'])) {
        //     ProcessNewPmLeaseOnTheMarketJob::dispatch();
        //     ProcessLOnTheMarketJob::dispatch();

        //     Log::info('Asana webhook event received', [
        //         'event' => $data['events'],
        //         'project_id' => $projectId ?? 'unknown',
        //     ]);
        // }

        return response()->noContent();

    }
}
