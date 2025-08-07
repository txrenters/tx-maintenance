<?php

namespace App\Http\Controllers;

use App\Models\JobberToken;
use Illuminate\Support\Facades\Http;

class JobberDiagnosticController extends Controller
{
    public function diagnose()
    {
        $diagnostics = [];

        // Check environment variables
        $diagnostics['environment'] = [
            'client_id_set' => ! empty(env('JOBBER_CLIENT_ID')),
            'client_secret_set' => ! empty(env('JOBBER_SECRET')),
            'callback_url_set' => ! empty(env('JOBBER_CALLBACK_URL')),
            'api_version_set' => ! empty(env('JOBBER_API_VERSION')),
            'callback_url' => env('JOBBER_CALLBACK_URL'),
            'api_version' => env('JOBBER_API_VERSION'),
        ];

        // Check token in database
        $token = JobberToken::first();
        $diagnostics['token'] = [
            'exists' => ! is_null($token),
            'has_access_token' => $token && ! empty($token->access_token),
            'has_refresh_token' => $token && ! empty($token->refresh_token),
            'expires_at' => $token ? $token->expires_at : null,
            'is_expired' => $token && $token->expires_at ? now()->isAfter($token->expires_at) : null,
            'created_at' => $token ? $token->created_at : null,
            'updated_at' => $token ? $token->updated_at : null,
        ];

        // Test API connection
        $diagnostics['api_test'] = $this->testApiConnection($token);

        // Test refresh token
        if ($token && $token->refresh_token) {
            $diagnostics['refresh_test'] = $this->testRefreshToken($token);
        }

        return response()->json($diagnostics);
    }

    private function testApiConnection($token)
    {
        if (! $token || ! $token->access_token) {
            return ['status' => 'skipped', 'reason' => 'No access token available'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->access_token,
                'X-JOBBER-GRAPHQL-VERSION' => env('JOBBER_API_VERSION'),
                'Content-Type' => 'application/json',
            ])->post('https://api.getjobber.com/api/graphql', [
                'query' => 'query { user { id } }',
            ]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'status_code' => $response->status(),
                'body' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function testRefreshToken($token)
    {
        try {
            $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
                'client_id' => env('JOBBER_CLIENT_ID'),
                'client_secret' => env('JOBBER_SECRET'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
            ]);

            return [
                'status' => $response->successful() ? 'success' : 'failed',
                'status_code' => $response->status(),
                'body' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function clearTokens()
    {
        JobberToken::truncate();

        return response()->json(['message' => 'All Jobber tokens cleared']);
    }
}
