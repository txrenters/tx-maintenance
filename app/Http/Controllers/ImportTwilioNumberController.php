<?php

namespace App\Http\Controllers;

use App\Services\TwilioPhoneNumberSyncService;
use Illuminate\Support\Facades\Log;

class ImportTwilioNumberController extends Controller
{
    public function __invoke(TwilioPhoneNumberSyncService $syncService)
    {
        try {
            $result = $syncService->sync();

            return redirect()->back()->with('success', "Twilio numbers synced. Total: {$result['total']}, Created: {$result['created']}, Updated: {$result['updated']}");
        } catch (\Throwable $e) {
            Log::error('Error importing Twilio numbers: '.$e->getMessage());

            return redirect()->back()->withErrors([
                'message' => 'Failed to sync Twilio numbers. Please try again.',
            ]);
        }

    }
}
