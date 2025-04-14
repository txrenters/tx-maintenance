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
    
        // Otherwise process real events
        $events = $request->json('events', []);
    
        if ($events) {
            Log::info('Received webhook events', ['events' => $events]);
    
            Artisan::call('asana:set-dues');
    
            return response()->json(['message' => 'Webhook processed'], 200);
        }
    
        Log::warning('No events found in the webhook request');
        return response()->json(['message' => 'No events found'], 400);
    }
    
}