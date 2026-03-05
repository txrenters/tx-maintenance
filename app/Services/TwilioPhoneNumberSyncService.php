<?php

namespace App\Services;

use App\Models\TwilioPhoneNumber;
use Illuminate\Support\Facades\DB;
use Twilio\Rest\Client;

class TwilioPhoneNumberSyncService
{
    public function sync(): array
    {
        $accountSid = (string) config('services.twilio.sid');
        $authToken = (string) config('services.twilio.auth_token');

        if ($accountSid === '' || $authToken === '') {
            throw new \RuntimeException('Twilio credentials are not configured.');
        }

        $client = new Client($accountSid, $authToken);
        $twilioNumbers = $client->incomingPhoneNumbers->read();

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($twilioNumbers, &$created, &$updated): void {
            foreach ($twilioNumbers as $twilio) {
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

                $existing = TwilioPhoneNumber::where('phone_number', $twilio->phoneNumber)->first();
                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    TwilioPhoneNumber::create($data);
                    $created++;
                }
            }
        });

        return [
            'total' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
        ];
    }
}
