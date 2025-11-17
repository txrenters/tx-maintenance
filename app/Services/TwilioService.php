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

    public function sendMessage($to, $from, $message, $mediaUrl = null)
    {
        try {
            $messageData = [
                'from' => $from,
                'body' => $message.' '.$mediaUrl,
            ];

            $this->client->messages->create($to, $messageData);

            Log::info('Message sent successfully', [
                'to' => $to,
                'from' => $from,
                'body' => $message,
                'media' => $mediaUrl ? 'included' : 'none',
            ]);

        } catch (\Exception $e) {
            Log::error('Message unsuccessfully: '.$e->getMessage());
        }
    }
}
