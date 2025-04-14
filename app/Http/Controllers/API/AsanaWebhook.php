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
        // Parse webhook events
        $events = $request->json('events', []);

        if($events){
            Log::info('Received webhook events', ['events' => $events]);

            Artisan::call('asana:set-dues', [
                '--events' => json_encode($events),
            ]);
            
            return response()->json(['message' => 'Webhook processed'], 200);

        } else {
            Log::warning('No events found in the webhook request');
            return response()->json(['message' => 'No events found'], 400);
        }
    }
}