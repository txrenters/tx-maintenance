<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\MediaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class TwilioWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        $this->forwardToPlusThis($data);

        // Validate and sanitize input
        $from = is_array($data['From']) ? implode(',', $data['From']) : (string) $data['From'];
        $to = is_array($data['To']) ? implode(',', $data['To']) : (string) $data['To'];
        $body = is_array($data['Body']) ? implode(',', $data['Body']) : (string) $data['Body'];

        $isMms = $data['NumMedia'] > 0;

        $type = $this->getMessageType($from, $to);
        $workOrderId = $this->getWorkOrderId($from, $to);
        $checkMessageDuplicate = $this->checkMessageDuplicate($from, $to, $body);

        if (! $checkMessageDuplicate && $workOrderId && $type) {
            try {

                $this->validateTwilioRequest($request);

                $conversation = Conversation::create([
                    'message' => $body,
                    'is_mms' => $isMms,
                    'conversation_type' => $type,
                    'receiver_number' => $to,
                    'sender_number' => $from,
                    'work_order_id' => $workOrderId,
                ]);

                if ($isMms) {
                    $this->processMediaAttachments($conversation, $data);
                }

                return response()->noContent(); // HTTP 204

            } catch (Exception $e) {
                Log::error('Failed to create conversation: '.$e->getMessage());

                return response('Error processing request', 500);
            }

        } else {
            Log::info('Message not valid for insertion (duplicate or missing data).');

            return response('Error processing request', 500);
        }
    }

    protected function handleMessage(array $data): void
    {
        $isMms = $data['NumMedia'] > 0;
        // $from = $this->formatNumber($data['From']);
        // $to = $this->formatNumber($data['To']);

        $from = $data['From'];
        $to = $data['To'];

        $type = $this->getMessageType($from, $to);
        $workOrderId = $this->getWorkOrderId($from, $to);
        $checkMessageDuplicate = $this->checkMessageDuplicate($from, $to, $data['Body']);

        if (! $checkMessageDuplicate && $workOrderId && $type) {
            $conversation = Conversation::create([
                'message' => $data['Body'],
                'is_mms' => $isMms,
                'conversation_type' => $type,
                'receiver_number' => $to,
                'sender_number' => $from,
                'work_order_id' => $workOrderId,
            ]);

            if ($isMms) {
                $this->processMediaAttachments($conversation, $data);
            }
        }
    }

    protected function forwardToPlusThis(array $data)
    {
        try {
            $forwardUrl = 'https://e.plusthis.com/webhooks/Twilio/sms/19802';

            // Send POST request with data to the other URL
            $response = Http::post($forwardUrl, $data);

            if ($response->successful()) {
                Log::info('Message Forwarded successfully to PlusThis.');
            } else {
                Log::error('Failed to forward data to PlusThis. Response: '.$response->body());
            }
        } catch (\Exception $e) {
            Log::error('Error forwarding data to PlusThis: '.$e->getMessage());
        }
    }

    protected function processMediaAttachments(Conversation $conversation, array $data): void
    {
        try {
            $mediaService = app(MediaService::class);

            for ($i = 0; $i < $data['NumMedia']; $i++) {
                $mediaService->downloadAndStore(
                    $data["MediaUrl{$i}"],
                    $data["MediaContentType{$i}"],
                    $conversation->id
                );
            }
        } catch (\Exception $e) {
            Log::error('Media attachment failed: '.$e->getMessage());
        }
    }

    protected function checkMessageDuplicate(string $from, string $to, string $msg): bool
    {
        $convo = Conversation::where('receiver_number', $to)
            ->where('sender_number', $from)
            ->latest()
            ->first();

        // If no conversation is found, return false
        if (! $convo) {
            return false;
            Log::info('Message not duplicate');
        }

        // Compare trimmed messages
        Log::info('Checking message:', ['message duplicate' => trim($convo->message) == trim($msg), 'data' => $convo->message.' - '.$msg]);

        return trim($convo->message) == trim($msg);
    }

    protected function getMessageType(string $from, string $to): string
    {
        $conversation = Conversation::where('receiver_number', $to)
            ->where('sender_number', $from)
            ->first();

        return $conversation->conversation_type ?? ''; // Provide a fallback
    }

    protected function getWorkOrderId(string $from, string $to)
    {
        $conversation = Conversation::where('receiver_number', $to)
            ->where('sender_number', $from)
            ->first();

        return $conversation->work_order_id ?? null;
    }

    protected function formatNumber(string $number): string
    {
        return '+1'.preg_replace('/[^0-9]/', '', $number);
    }

    protected function validateTwilioRequest(Request $request): void
    {
        if (app()->environment('local')) {
            return; // Skip validation for local environment
        }

        $validator = new RequestValidator(env('TWILIO_AUTH_TOKEN'));

        if (! $validator->validate(
            $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->toArray()
        )) {
            abort(403, 'Invalid Twilio request signature');
        }
    }
}
