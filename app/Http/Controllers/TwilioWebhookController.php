<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\WorkOrder;
use App\Services\MediaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $workOrderMessage = $this->getWorkOrderMessage($body, $from, $to);

        if ($workOrderMessage) {

            $type = $workOrderMessage->conversation_type ?? ''; // Provide a fallback
            $workOrderId = $workOrderMessage->work_order_id ?? ''; // Provide a fallback

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

                    $workOrder = WorkOrder::find($workOrderId);

                    activity()
                        ->performedOn($conversation)
                        ->event('work_order_message_received')
                        ->withProperties([
                            'senderNumber' => $from,
                            'receiverNumber' => $to,
                            'message' => $body,
                            'work_order_id' => $workOrder->id,
                        ])
                        ->log('Work Order #'.$workOrder->work_order_no.' - New Message Received');

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

        if ($jobberMessage) {

            $numMedia = (int) $request->input('NumMedia');
            $hasVisitColumn = JobberTextMessage::hasVisitColumn();

            $payload = [
                'sender_number' => $from,
                'receiver_number' => $to,
                'messages' => $body,
                'image' => $numMedia > 0 ? $request->input('MediaUrl0') : null,
                'jobber_id' => $jobberMessage->jobber_id,
            ];

            if ($hasVisitColumn) {
                $payload['jobber_visit_id'] = $jobberMessage->jobber_visit_id ?? null;
            }

            $textMessage = JobberTextMessage::create($payload);

            if ($numMedia > 1) {
                $mediaWithTextMessage = [];

                for ($i = 1; $i < $numMedia; $i++) {
                    $mediaUrl = $request->input("MediaUrl{$i}");

                    $mediaWithTextMessage[] = [
                        'sender_number' => $from,
                        'receiver_number' => $to,
                        'messages' => '',
                        'image' => $mediaUrl,
                        'jobber_id' => $jobberMessage->jobber_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($hasVisitColumn) {
                        $mediaWithTextMessage[count($mediaWithTextMessage) - 1]['jobber_visit_id'] = $jobberMessage->jobber_visit_id ?? null;
                    }
                }

                DB::table('jobber_text_messages')->insert($mediaWithTextMessage);
            }

            $jobber = Jobber::find($jobberMessage->jobber_id);

            activity()
                ->performedOn($textMessage)
                ->event('job_message_received')
                ->withProperties([
                    'senderNumber' => $from,
                    'receiverNumber' => $to,
                    'message' => $body,
                    'job_id' => $jobber->id,
                ])
                ->log('Job #'.$jobber->job_number.' - New Message Received');

            Log::info('Jobber Message saved successfully into the database.', ['data' => $textMessage]);

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
                    'data' => $data,
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

    protected function getWorkOrderMessage(string $body, string $from, string $to)
    {
        // "Ref: WO#12345" just in case
        if (preg_match('/Ref:\s*(WO#\d+)/', $body, $matches)) {
            $refNo = $matches[1];
            $workOrder = WorkOrder::where('work_order_no', $refNo)->first();

            return Conversation::where('work_order_id', $workOrder->id)->latest()
                ->first();
        }

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
        })
            ->whereHas('jobber', function ($query) {
                $query->where('job_status', '!=', 'archived');
            })
            ->latest('created_at')
            ->first();
    }

    protected function getWorkOrderId(string $from, string $to)
    {
        $conversation = Conversation::where('receiver_number', $from)
            ->where('sender_number', $to)
            ->first();

        return $conversation->work_order_id ?? null;
    }
}
