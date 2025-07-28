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
        Log::info('Data received:', ['data' => $request->all()]);
        $code = $request->query('code');

        if (!$code) {
            return response()->json(['error' => 'No authorization code provided'], 400);
        }

        $response = Http::asForm()->post('https://api.getjobber.com/api/oauth/token', [
            'client_id' => env('JOBBER_CLIENT_ID'),
            'client_secret' => env('JOBBER_CLIENT_SECRET'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => env('JOBBER_CALLBACK_URL'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            dd($response->body()); // or log it
        }

        $data = $response->json();

        // Save token
        JobberToken::create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds(3600), // Adjust if Jobber returns an exact expiry
        ]);

        return response()->json(['message' => 'Jobber tokens saved successfully!']);
    }
}
