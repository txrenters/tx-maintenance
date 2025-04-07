<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterAsanaWebhook extends Command
{
    protected $signature = 'asana:register-webhook';
    protected $description = 'Registers Asana webhooks for all projects';

    public function handle()
    {
        $token = env('ASANA_ACCESS_TOKEN');
        $webhookUrl = env('ASANA_WEBHOOK_URL');
        $projectIds = [env('ASANA_PROJECT_ID_L_ON_THE_MARKET'), env('ASANA_PROJECT_ID_NEW_PM_LEASE_ON_THE_MARKET')]; // Replace with actual Asana project GIDs

        try {
            foreach ($projectIds as $projectId) {
            
                // ✅ Fetch existing webhooks
                $response = Http::withToken($token)->get('https://app.asana.com/api/1.0/webhooks', [
                    'resource' => $projectId
                ]);
            
                $webhooks = $response->json()['data'] ?? [];
            
                foreach ($webhooks as $webhook) {
                    $webhookId = $webhook['gid'];
                    Log::info('Attempting to delete webhook', ['webhook_id' => $webhookId, 'project_id' => $projectId]);
            
                    // ✅ Delete webhook
                    $deleteResponse = Http::withToken($token)
                        ->withHeaders([
                            'Asana-Disable' => 'new_goal_memberships'
                        ])
                        ->delete("https://app.asana.com/api/1.0/webhooks/{$webhookId}");
            
                    if (!$deleteResponse->successful()) {
                        Log::error('Failed to delete webhook', [
                            'webhook_id' => $webhookId, // ✅ Corrected
                            'project_id' => $projectId,
                            'status' => $deleteResponse->status(),
                            'body' => $deleteResponse->body()
                        ]);
                    } else {
                        Log::info('Webhook deleted successfully', ['webhook_id' => $webhookId, 'project_id' => $projectId]);
                    }
                }
            }
            

            foreach ($projectIds as $projectId) {
                // ✅ Register a new webhook
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Asana-Enable' => 'new_goal_memberships',
                        'Content-Type' => 'application/json'
                    ])
                    ->post('https://app.asana.com/api/1.0/webhooks', [
                        'data' => [
                            'resource' => $projectId,
                            'target' => $webhookUrl
                        ]
                    ]);

                if ($response->successful()) {
                    Log::info('Webhook registered for project', ['project_id' => $projectId]);
                } else {
                    Log::error('Failed to register webhook', [
                        'project_id' => $projectId,
                        'response' => $response->json()
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to register webhook', ['error' => $e->getMessage()]);
        }
    }
}
