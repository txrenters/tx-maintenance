<?php

namespace App\Http\Controllers;

use App\Services\JobberTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobberAuthController extends Controller
{
    public function __construct(private JobberTokenService $tokens) {}

    public function handleCallback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        // The state was stored in the session when the admin started the OAuth
        // flow at /jobber-connect; a mismatch means this callback was not
        // initiated by us, so never exchange its code.
        $expectedState = $request->session()->pull('jobber_oauth_state');

        if (blank($expectedState) || $state !== $expectedState) {
            Log::warning('Jobber OAuth callback rejected: state mismatch.');

            return redirect('/inspections')->with('error', 'Jobber connection failed: invalid state. Please try connecting again.');
        }

        if (! $code) {
            Log::error('Jobber OAuth callback missing authorization code.');

            return redirect('/inspections')->with('error', ['error', 'No authorization code provided']);
        }

        try {
            $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.jobber.client_id'),
                'client_secret' => config('services.jobber.client_secret'),
                'redirect_uri' => config('services.jobber.callback_url'),
                'code' => $code,
            ]);

            if ($response->failed()) {
                Log::error('Token exchange failed: '.$response->body());

                return redirect('/inspections')->with('error', ['error', 'Token exchange failed: '.$response->body()]);
            }

            $this->tokens->storeTokensFromOAuth($response->json());

            Log::info('Jobber connected via OAuth callback.');

            return redirect('/inspections')->with('success', 'Connected to Jobber');

        } catch (\Exception $e) {
            Log::error('Exception: '.$e->getMessage());

            return redirect('/inspections')->with('error', 'Exception: '.$e->getMessage());
        }
    }

    public function refreshAccessToken(): string
    {
        return $this->tokens->refreshAccessToken();
    }

    public function ensureValidToken(): string
    {
        return $this->tokens->getAccessToken();
    }
}
