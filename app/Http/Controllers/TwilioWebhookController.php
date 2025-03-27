<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use App\Services\MediaService;
use Twilio\Security\RequestValidator;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            $this->validateTwilioRequest($request);
            $data = $request->all();

              // Only process inbound messages
            if (($data['Direction'] ?? 'inbound') !== 'inbound') {
                return response('', 200);
            }

            if (isset($data['SmsStatus'])) {
                $this->handleMessage($data);
            }

            return response()->noContent(); // HTTP 204

        } catch (\Exception $e) {
            Log::error('Twilio webhook error: ' . $e->getMessage());
            return response('Error processing request', 500);
        }
    }

    protected function handleMessage(array $data): void
    {
        $isMms = $data['NumMedia'] > 0;
        $from = $this->formatNumber($data['From']);
        $to = $this->formatNumber($data['To']);

      

        if ($workOrderId = $this->getWorkOrderId($from, $to)) {
            $conversation = Conversation::create([
                'message' => $data['Body'],
                'is_mms' => $isMms,
                'conversation_type' => $this->getMessageType($from, $to),
                'receiver_number' => $from,
                'sender_number' => $to,
                'work_order_id' => $workOrderId,
                'status' => $data['SmsStatus']
            ]);

            if ($isMms) {
                $this->processMediaAttachments($conversation, $data);
            }
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
            Log::error('Media attachment failed: ' . $e->getMessage());
        }
    }

    protected function getMessageType(string $from, string $to): string
    {
        $conversation = Conversation::where('receiver_number', $to)
            ->where('sender_number', $from)
            ->first();

        return $conversation->type ?? 'default_type'; // Provide a fallback
    }

    protected function getWorkOrderId(string $from, string $to): ?int
    {
        $conversation = Conversation::where('receiver_number', $to)
            ->where('sender_number', $from)
            ->first();

        return $conversation->work_order_id ?? null;
    }

    protected function formatNumber(string $number): string
    {
        return '+' . preg_replace('/[^0-9]/', '', $number);
    }

    protected function validateTwilioRequest(Request $request): void
    {
        $validator = new RequestValidator(env('TWILIO_AUTH_TOKEN'));
        
        if (!$validator->validate(
            $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->toArray()
        )) {
            abort(403, 'Invalid Twilio request signature');
        }
    }
}