<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\TwilioPhoneNumber;
use App\Models\WorkOrder;
use App\Services\MediaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TwilioWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();
        $messageSid = (string) ($request->input('MessageSid') ?: $request->input('SmsSid', ''));
        $messageStatus = (string) $request->input('MessageStatus', '');

        // Some Twilio setups send status callbacks to this same endpoint.
        if ($messageSid !== '' && $messageStatus !== '') {
            return $this->statusCallback($request);
        }

        // Ignore webhook retries/replays for a SID we already stored.
        if ($messageSid !== '') {
            $existingConversation = Conversation::where('twilio_sid', $messageSid)->latest('id')->first();
            if ($existingConversation) {
                Log::info('Twilio webhook duplicate ignored for existing SID', [
                    'sid' => $messageSid,
                    'conversation_id' => $existingConversation->id,
                ]);

                return response()->noContent();
            }
        }

        $this->forwardToPlusThis($data);

        $fromRaw = $request->input('From');
        $toRaw = $request->input('To');
        $bodyRaw = $request->input('Body', '');

        $from = is_array($fromRaw) ? implode(',', $fromRaw) : (string) $fromRaw;
        $to = is_array($toRaw) ? implode(',', $toRaw) : (string) $toRaw;
        $body = is_array($bodyRaw) ? implode(',', $bodyRaw) : (string) $bodyRaw;

        if ($from === '' || $to === '') {
            Log::warning('Twilio webhook missing From/To fields', [
                'payload' => $data,
            ]);

            return response()->noContent();
        }

        $isMms = isset($data['NumMedia']) && $data['NumMedia'] > 0;

        $workOrderMessage = $this->getWorkOrderMessage($body, $from, $to);

        if ($workOrderMessage) {

            $type = $workOrderMessage->conversation_type ?? ''; // Provide a fallback
            $workOrderId = $workOrderMessage->work_order_id ?? ''; // Provide a fallback

            if ($workOrderId && $type) {
                if ($this->isInternalMirrorOfRecentOutbound(
                    from: $from,
                    to: $to,
                    body: $body,
                    workOrderId: (int) $workOrderId,
                    conversationType: (string) $type,
                    incomingSid: $messageSid !== '' ? $messageSid : null
                )) {
                    return response()->noContent();
                }

                try {
                    $conversation = Conversation::create([
                        'message' => $body,
                        'is_mms' => $isMms,
                        'conversation_type' => $type,
                        'receiver_number' => $to,
                        'sender_number' => $from,
                        'work_order_id' => $workOrderId,
                        'twilio_sid' => $messageSid !== '' ? $messageSid : null,
                    ]);

                    if ($isMms) {
                        $this->processMediaAttachments($conversation, $data);
                    }
                    Log::info('Message saved successfully into the database.', ['data' => $conversation]);

                    $workOrder = WorkOrder::find($workOrderId);
                    $resolvedWorkOrderId = $workOrder?->id ?? $workOrderId;
                    $resolvedWorkOrderNo = $workOrder?->work_order_no ?? $workOrderId;

                    activity()
                        ->performedOn($conversation)
                        ->event('work_order_message_received')
                        ->withProperties([
                            'senderNumber' => $from,
                            'receiverNumber' => $to,
                            'message' => $body,
                            'work_order_id' => $resolvedWorkOrderId,
                        ])
                        ->log('Work Order #'.$resolvedWorkOrderNo.' - New Message Received');

                    return response()->noContent(); // HTTP 204

                } catch (Exception $e) {
                    Log::error('Failed to create conversation: '.$e->getMessage());

                    return response()->noContent();
                }

            } else {
                Log::info('Message not valid for insertion (duplicate or missing data).');

                return response()->noContent();
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
            $resolvedJobId = $jobber?->id ?? $jobberMessage->jobber_id;
            $resolvedJobNumber = $jobber?->job_number ?? $jobberMessage->jobber_id;

            activity()
                ->performedOn($textMessage)
                ->event('job_message_received')
                ->withProperties([
                    'senderNumber' => $from,
                    'receiverNumber' => $to,
                    'message' => $body,
                    'job_id' => $resolvedJobId,
                ])
                ->log('Job #'.$resolvedJobNumber.' - New Message Received');

            Log::info('Jobber Message saved successfully into the database.', ['data' => $textMessage]);

            return response()->noContent();
        }

        Log::info('Message not found in the database.');

        return response()->noContent();
    }

    public function statusCallback(Request $request)
    {
        try {
            $messageSid = (string) ($request->input('MessageSid') ?: $request->input('SmsSid', ''));
            $messageStatus = (string) $request->input('MessageStatus', '');
            $errorCode = $request->input('ErrorCode');
            $errorMessage = $request->input('ErrorMessage');

            if ($messageSid === '') {
                Log::warning('Twilio status callback missing MessageSid', [
                    'payload' => $request->all(),
                ]);

                return response()->noContent();
            }

            $conversation = Conversation::where('twilio_sid', $messageSid)->latest('id')->first();
            if ($conversation) {
                $conversation->update([
                    'twilio_status' => $messageStatus !== '' ? $messageStatus : null,
                    'twilio_status_updated_at' => now(),
                    'twilio_error_code' => $errorCode ? (string) $errorCode : null,
                    'twilio_error_message' => $errorMessage ?: null,
                ]);

                if (in_array($messageStatus, ['failed', 'undelivered'], true)) {
                    Log::warning('Twilio delivery failure', [
                        'conversation_id' => $conversation->id,
                        'work_order_id' => $conversation->work_order_id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'to' => $conversation->receiver_number,
                        'from' => $conversation->sender_number,
                    ]);
                } else {
                    Log::info('Twilio delivery status updated', [
                        'conversation_id' => $conversation->id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                    ]);
                }

                return response()->noContent();
            }

            if (Schema::hasColumn('jobber_text_messages', 'twilio_sid')) {
                $jobberMessage = JobberTextMessage::where('twilio_sid', $messageSid)->latest('id')->first();

                if ($jobberMessage) {
                    $updates = [];

                    if (Schema::hasColumn('jobber_text_messages', 'twilio_status')) {
                        $updates['twilio_status'] = $messageStatus !== '' ? $messageStatus : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_status_updated_at')) {
                        $updates['twilio_status_updated_at'] = now();
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_error_code')) {
                        $updates['twilio_error_code'] = $errorCode ? (string) $errorCode : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'twilio_error_message')) {
                        $updates['twilio_error_message'] = $errorMessage ?: null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'status')) {
                        $updates['status'] = $messageStatus !== '' ? $messageStatus : null;
                    }
                    if (Schema::hasColumn('jobber_text_messages', 'error_message')) {
                        $updates['error_message'] = $errorMessage ?: null;
                    }

                    if (! empty($updates)) {
                        $jobberMessage->update($updates);
                    }

                    Log::info('Twilio delivery status updated for jobber message', [
                        'jobber_text_message_id' => $jobberMessage->id,
                        'sid' => $messageSid,
                        'status' => $messageStatus,
                    ]);

                    return response()->noContent();
                }
            }

            Log::warning('Twilio status callback received for unknown SID', [
                'sid' => $messageSid,
                'status' => $messageStatus,
                'to' => $request->input('To'),
                'from' => $request->input('From'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Twilio status callback processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->noContent();
        }

        return response()->noContent();
    }

    protected function isInternalMirrorOfRecentOutbound(
        string $from,
        string $to,
        string $body,
        int $workOrderId,
        string $conversationType,
        ?string $incomingSid = null
    ): bool {
        if (! $this->isManagedTwilioNumber($from) || ! $this->isManagedTwilioNumber($to)) {
            return false;
        }

        $normalizedBody = $this->normalizeMessageBody($body);

        $recentOutboundCandidates = Conversation::query()
            ->where('sender_number', $from)
            ->where('receiver_number', $to)
            ->where('work_order_id', $workOrderId)
            ->where('conversation_type', $conversationType)
            ->whereNotNull('twilio_sid')
            ->where('created_at', '>=', now()->subSeconds(90))
            ->latest('id')
            ->get(['id', 'message', 'twilio_sid', 'created_at']);

        foreach ($recentOutboundCandidates as $candidate) {
            if ($incomingSid && $candidate->twilio_sid === $incomingSid) {
                continue;
            }

            if ($this->normalizeMessageBody((string) $candidate->message) !== $normalizedBody) {
                continue;
            }

            Log::info('Skipped mirrored Twilio internal inbound copy', [
                'work_order_id' => $workOrderId,
                'conversation_type' => $conversationType,
                'from' => $from,
                'to' => $to,
                'incoming_sid' => $incomingSid,
                'matched_conversation_id' => $candidate->id,
                'matched_conversation_sid' => $candidate->twilio_sid,
                'matched_created_at' => $candidate->created_at instanceof Carbon
                    ? $candidate->created_at->toIso8601String()
                    : $candidate->created_at,
            ]);

            return true;
        }

        return false;
    }

    protected function normalizeMessageBody(string $body): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($body));

        return $normalized ?? '';
    }

    protected function isManagedTwilioNumber(string $number): bool
    {
        static $managedNumbers = null;

        if ($managedNumbers === null) {
            $managedNumbers = TwilioPhoneNumber::query()
                ->pluck('phone_number')
                ->map(fn ($phone) => $this->normalizePhone((string) $phone))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return in_array($this->normalizePhone($number), $managedNumbers, true);
    }

    protected function normalizePhone(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?: '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
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
        // Prefer explicit "Ref: WO#12345" when present.
        if (preg_match('/Ref:\s*(WO#\d+)/i', $body, $matches)) {
            $refNo = $matches[1];
            $workOrder = WorkOrder::where('work_order_no', $refNo)->first();

            if ($workOrder) {
                $matchedConversation = Conversation::where('work_order_id', $workOrder->id)
                    ->latest()
                    ->first();

                if ($matchedConversation) {
                    return $matchedConversation;
                }
            } else {
                Log::warning('Twilio webhook reference did not match a work order', [
                    'reference' => $refNo,
                    'from' => $from,
                    'to' => $to,
                ]);
            }
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
