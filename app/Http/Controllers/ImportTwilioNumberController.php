<?php

namespace App\Http\Controllers;

use App\Models\TwilioPhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class ImportTwilioNumberController extends Controller
{
    public function __invoke()
    {
        $account_sid = env('TWILIO_SID');
        $auth_token = env('TWILIO_AUTH_TOKEN');

        $client = new Client($account_sid, $auth_token);

        $twilioNumbers = $client->incomingPhoneNumbers;

        DB::beginTransaction(); // Start Transaction for null values

        try {
            $data = [];

            foreach ($twilioNumbers->read() as $twilio) {
                $capabilities = [
                    'mms' => $twilio->capabilities->mms ? 'Yes' : 'No',
                    'sms' => $twilio->capabilities->sms ? 'Yes' : 'No',
                    'voice' => $twilio->capabilities->voice ? 'Yes' : 'No',
                    'fax' => $twilio->capabilities->fax ? 'Yes' : 'No',
                ];

                $data = [
                        'name' => $twilio->friendlyName,
                        'account_sid' => $twilio->accountSid,
                        'sid' => $twilio->sid,
                        'phone_number' => $twilio->phoneNumber,
                        'sms_application_sid' => $twilio->smsApplicationSid ?? null,
                        'capabilities' => json_encode($capabilities),
                        'twilio_status' => $twilio->status ?? 'unknown',
                    ];

                $twilioPhone = TwilioPhoneNumber::where('phone_number', $twilio->phoneNumber)->first();
 
                if($twilioPhone){
                    $twilioPhone->update($data);
                }else{
                    TwilioPhoneNumber::create($data);
                }
            }
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback on error
            Log::error('Error importing Twilio numbers: '.$e->getMessage());
        }

    }
}
