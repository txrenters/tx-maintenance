<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Services\MediaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Calculation\Logical\Boolean;
use PhpParser\Node\Expr\Cast\Object_;

class TwilioWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        $this->forwardToPlusThis($data);

        $from = is_array($data['From']) ? implode(',', $data['From']) : (string) $data['From'];
        $to = is_array($data['To']) ? implode(',', $data['To']) : (string) $data['To'];
        $body = is_array($data['Body']) ? implode(',', $data['Body']) : (string) $data['Body'];

        $isMms = isset($data['NumMedia']) && $data['NumMedia'] > 0;

        Log::info('New SMS message received', [
            'phone' => $from,
            'message' => $body,
            'message_length' => strlen($body),
            'has_media' => $request->hasMedia(),
            'media_count' => $request->getMediaCount(),
        ]);

        $workOrderMessage = $this->getWorkOrderMessage($from, $to);

        if ($workOrderMessage) {

            $type = $message->conversation_type ?? ''; // Provide a fallback
            $workOrderId = $message->work_order_id ?? ''; // Provide a fallback

            if ($workOrderId && $type) {
                try {
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
                    Log::info('Message saved successfully into the database.', ['data' => $conversation]);

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

        $jobberMessage = $this->getJobberMessage($from, $to);

        if($jobberMessage){

            $imageUrl = null;
            if ($request->hasMedia()) {
                $imageUrl = $request->getFirstMediaUrl();
            }

            $textMessage = JobberTextMessage::create([
                'message' => $body,
                'sender_number' => $from,
                'receiver_number' => $to,
                'image' => $imageUrl,
                'jobber_job_id' => $jobberMessage->jobber_job_id
            ]);
            
            Log::info('Message saved successfully into the database.', ['data' => $textMessage]);

            return response()->noContent(); 
        }

        Log::info('Message not found in the database.');
        return response('Error processing request', 500);
    }

    protected function forwardToPlusThis(array $data)
    {
        try {
            $forwardUrl = 'https://e.plusthis.com/webhooks/Twilio/sms/19802';

            // Send POST requests separately
            $plusThisResponse = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post($forwardUrl, $data);

            if ($plusThisResponse->successful()) {

                Log::info('Text message information:', [
                    'data' => $$data,
                ]);

                Log::info('Message forwarded successfully to PlusThis');
            } else {
                if (! $plusThisResponse->successful()) {
                    Log::error('Failed to forward data to PlusThis. Response: '.$plusThisResponse->body());
                }
            }
        } catch (\Exception $e) {
            Log::error('Error forwarding data: '.$e->getMessage());
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

    protected function getWorkOrderMessage(string $from, string $to)
    {
        return Conversation::where(function ($query) use ($from, $to) {
            $query->where('receiver_number', $from)
                ->where('sender_number', $to);
        })->orWhere(function ($query) use ($to, $from) {
            $query->where('receiver_number', $to)
                ->where('sender_number', $from);
        })->latest()
            ->first(); // fetch the latest conversation
    }

    protected function getJobberMessage(string $from, string $to)
    {
        return JobberTextMessage::where(function ($query) use ($from, $to) {
            $query->where('receiver_number', $from)
                ->where('sender_number', $to);
        })->orWhere(function ($query) use ($to, $from) {
            $query->where('receiver_number', $to)
                ->where('sender_number', $from);
        })->first(); // fetch the latest conversation
    }

    protected function getMessageType(string $from, string $to): string
    {
        $conversation = Conversation::where('receiver_number', $from)
            ->where('sender_number', $to)
            ->first();

        return $conversation->conversation_type ?? ''; // Provide a fallback
    }

    protected function getWorkOrderId(string $from, string $to)
    {
        $conversation = Conversation::where('receiver_number', $from)
            ->where('sender_number', $to)
            ->first();

        return $conversation->work_order_id ?? null;
    }

    protected function formatNumber(string $number): string
    {
        return '+1'.preg_replace('/[^0-9]/', '', $number);
    }
}
