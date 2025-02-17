<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class ImportTwilioNumberController extends Controller
{
    public function __invoke()
    {

        $account_sid  = env('TWILIO_SID');
        $auth_token  = env('TWILIO_AUTH_TOKEN');

        $client = new Client($account_sid, $auth_token);

        $twilioNumbers = $client->incomingPhoneNumbers;


        DB::beginTransaction(); // Start Transaction for null values

        try {
            $data = []; 
        
            foreach ($twilioNumbers->read() as $twilio) {
                $phoneNumber = TwilioPhoneNumber::where('phone_number', $twilio->phoneNumber)->first();

                $capabilities = [
                    'mms' => $twilio->capabilities->mms ? 'Yes' : 'No',
                    'sms' => $twilio->capabilities->sms ? 'Yes' : 'No',
                    'voice' => $twilio->capabilities->voice ? 'Yes' : 'No',
                    'fax' => $twilio->capabilities->fax ? 'Yes' : 'No',
                ];

                if (!$phoneNumber) {
                    $data[] = [
                        'name' => $twilio->friendlyName,
                        'account_sid' => $twilio->accountSid,
                        'sid' => $twilio->sid,
                        'phone_number' => $twilio->phoneNumber,
                        'sms_application_sid' => $twilio->smsApplicationSid ?? null,
                        'capabilities' => json_encode($capabilities),
                        'twilio_status' => $twilio->status ?? 'unknown',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        
            if (!empty($data)) {
                TwilioPhoneNumber::insert($data);
                DB::commit(); // Commit the transaction
                Log::info("Successfully imported Twilio numbers.");
            } else {
                DB::rollBack(); // Rollback transaction (optional, as nothing was inserted)
                Log::info("No matching Twilio numbers found to insert.");
            }
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback on error
            Log::error("Error importing Twilio numbers: " . $e->getMessage());
        }

    }
}
