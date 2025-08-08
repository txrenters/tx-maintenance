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
                'body' => $message,
            ];

            // Add media URL if provided for MMS
            if ($mediaUrl) {
                $messageData['mediaUrl'] = [$mediaUrl];
            }

            $this->client->messages->create($to, $messageData);

            Log::info('Message sent successfully', [
                'from' => $from,
                'body' => $message,
                'media' => $mediaUrl ? 'included' : 'none',
            ]);

        } catch (\Exception $e) {
            Log::error('Message unsuccessfully: '.$e->getMessage());
        }
    }
}
