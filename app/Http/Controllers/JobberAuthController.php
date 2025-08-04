<?php

namespace App\Http\Controllers;

use App\Models\JobberToken;
use Illuminate\Http\Request;
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

    public function refreshAccessToken()
    {
        $token = JobberToken::first(); // adjust as needed

        $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
            'client_id' => env('JOBBER_CLIENT_ID'),
            'client_secret' => env('JOBBER_CLIENT_SECRET'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->ok()) {
            $data = $response->json();

            $token->access_token = $data['access_token'];
            if (isset($data['refresh_token'])) {
                $token->refresh_token = $data['refresh_token']; // optional if rotation is off
            }
            $token->save();

            return $data['access_token'];
        }

        throw new \Exception('Unable to refresh Jobber access token');
    }
}
