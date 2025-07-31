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
            ]);

            return redirect('/inspections')->with('success', 'Connected to Jobber');

        } catch (\Exception $e) {
            Log::error('Exception: '.$e->getMessage());

            return redirect('/inspections')->with('error', 'Exception: '.$e->getMessage());
        }
    }
}
