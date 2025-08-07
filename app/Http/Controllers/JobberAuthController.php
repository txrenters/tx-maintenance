<?php

namespace App\Http\Controllers;

use App\Models\JobberToken;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobberAuthController extends Controller
{
    public function handleCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->get('state');

        if (! $code) {
            Log::error('No authorization code provided: ');

            return redirect('/inspections')->with('error', ['error', 'No authorization code provided']);
        }

        try {
            $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => env('JOBBER_CLIENT_ID'),
                'client_secret' => env('JOBBER_SECRET'),
                'redirect_uri' => env('JOBBER_CALLBACK_URL'), // or hardcode your redirect URI
                'code' => $code,
            ]);

            if ($response->failed()) {
                Log::error('Token exchange failed: '.$response->body());

                return redirect('/inspections')->with('error', ['error', 'Token exchange failed: '.$response->body()]);
            }

            $data = $response->json();
            $accessToken = $data['access_token'];
            $refreshToken = $data['refresh_token'];

            Log::info('Tokens: ', ['data' => $data]);
            // Save to DB or session
            JobberToken::updateOrCreate([], [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addHour(),
            ]);

            return redirect('/inspections')->with('success', 'Connected to Jobber');

        } catch (\Exception $e) {
            Log::error('Exception: '.$e->getMessage());

            return redirect('/inspections')->with('error', 'Exception: '.$e->getMessage());
        }
    }

    public function refreshAccessToken(): string
    {
        // Fetch the latest token row (assuming single-row table)
        $token = JobberToken::first();

        if (! $token || ! $token->refresh_token) {
            Log::error('No Jobber refresh token found - manual reconnection required');
            throw new \Exception('No Jobber refresh token found. Please reconnect to Jobber at /jobber-connect');
        }

        // Perform token refresh request
        $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
            'client_id' => env('JOBBER_CLIENT_ID'),
            'client_secret' => env('JOBBER_SECRET'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->ok()) {
            $data = $response->json();

            // 🌐 Use DB transaction to avoid race conditions
            DB::transaction(function () use ($token, $data) {
                $token->access_token = $data['access_token'];

                if (isset($data['refresh_token'])) {
                    $token->refresh_token = $data['refresh_token'];
                }

                // You may store `expires_at` as well if needed
                if (isset($data['expires_at'])) {
                    $token->expires_at = Carbon::parse($data['expires_at']) ?? now()->addHour();
                } else {
                    // Default to 1 hour expiry if not provided
                    $token->expires_at = now()->addHour();
                }

                $token->save();
            });

            Log::info('Jobber access and refresh tokens updated.');

            return $data['access_token'];
        }

        Log::error('Failed to refresh Jobber token', [
            'status' => $response->status(),
            'body' => $response->body(),
            'request' => [
                'client_id' => env('JOBBER_CLIENT_ID'),
                'has_refresh_token' => ! empty($token->refresh_token),
            ],
        ]);

        // If we get a 401, the refresh token is invalid
        if ($response->status() === 401) {
            throw new \Exception('Refresh token is invalid. Please reconnect to Jobber.');
        }

        throw new \Exception('Unable to refresh Jobber access token: '.$response->body());
    }

    public function ensureValidToken(): string
    {
        $token = JobberToken::first();

        if (! $token) {
            throw new \Exception('No Jobber token found. Please connect to Jobber at /jobber-connect');
        }

        // Check if token is expired or expires soon (within 5 minutes)
        if (! $token->expires_at || now()->addMinutes(5)->isAfter($token->expires_at)) {
            Log::info('Jobber token expired or expiring soon, refreshing...');

            return $this->refreshAccessToken();
        }

        return $token->access_token;
    }
}
