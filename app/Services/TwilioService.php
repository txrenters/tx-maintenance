<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwilioService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client(
            env('TWILIO_SID'), 
            env('TWILIO_AUTH_TOKEN')
        );
    }

    public function sendMessage($to, $from, $message)
    {
        try {
            $this->client->messages->create($to, [
                'from' => $from,
                'body' => $message,
            ]);

            Log::info('Message sent successfully');
        } catch (\Exception $e) {
            Log::error('Message unsuccessfully: '.$e->getMessage());
        }
    }
}
