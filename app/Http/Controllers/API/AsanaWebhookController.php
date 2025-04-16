<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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

        $event = $request->all();

        if ($event) {
            // Log the event for debugging purposes
            Log::info('Asana Webhook Event:', $event);

            Artisan::call('asana:set-dues');

        } else {
            Log::error('No event data received from Asana webhook.');
        }

    }
}
