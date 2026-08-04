<?php

namespace App\Http\Controllers;

use App\Models\JobberToken;
use App\Services\JobberTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Jobber connection diagnostics for admins. The old "refresh token
 * test" is gone on purpose: Jobber refresh tokens are single-use, so testing
 * one consumed it and killed the live connection every time this ran.
 */
class JobberDiagnosticController extends Controller
{
    public function __construct(private JobberTokenService $tokens) {}

    public function diagnose(Request $request)
    {
        $this->authorizeAdmin($request);

        $diagnostics = [];

        // Check configuration
        $diagnostics['environment'] = [
            'client_id_set' => filled(config('services.jobber.client_id')),
            'client_secret_set' => filled(config('services.jobber.client_secret')),
            'callback_url_set' => filled(config('services.jobber.callback_url')),
            'api_version_set' => filled(config('services.jobber.api_version')),
            'callback_url' => config('services.jobber.callback_url'),
            'api_version' => config('services.jobber.api_version'),
        ];

        // Check token in database
        $token = JobberToken::first();
        $diagnostics['token'] = [
            'exists' => ! is_null($token),
            'has_access_token' => $token && ! empty($token->access_token),
            'has_refresh_token' => $token && ! empty($token->refresh_token),
            'expires_at' => $token ? $token->expires_at : null,
            'is_expired' => $token && $token->expires_at ? now()->isAfter($token->expires_at) : null,
            'needs_reconnect' => $this->tokens->needsReconnect(),
            'created_at' => $token ? $token->created_at : null,
            'updated_at' => $token ? $token->updated_at : null,
        ];

        // Test API connection (read-only query; never touches the refresh token)
        $diagnostics['api_test'] = $this->testApiConnection($token);

        return response()->json($diagnostics);
    }

    private function testApiConnection(?JobberToken $token): array
    {
        if (! $token || ! $token->access_token) {
            return ['status' => 'skipped', 'reason' => 'No access token available'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->access_token,
                'X-JOBBER-GRAPHQL-VERSION' => config('services.jobber.api_version'),
                'Content-Type' => 'application/json',
            ])->post(config('services.jobber.graphql_url'), [
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

    public function clearTokens(Request $request)
    {
        $this->authorizeAdmin($request);

        JobberToken::truncate();

        return response()->json(['message' => 'All Jobber tokens cleared']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);
    }
}
