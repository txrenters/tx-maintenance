<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\TwilioPhoneNumber;
use App\Models\WorkOrder;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Processes a single inbound Twilio message payload — used by both the live webhook
 * (TwilioWebhookController) and the backfill command (ImportTwilioInboundMessages).
 *
 * Result tokens:
 *   - duplicate:        SID already stored
 *   - mirror:           inbound is an internal echo of a recent outbound
 *   - missing_fields:   From or To missing
 *   - work_order:       stored as a work order Conversation
 *   - jobber:           stored as a JobberTextMessage
 *   - unmatched:        no matching thread for the phone pair
 */
class InboundTwilioMessageProcessor
{
    public function __construct(protected MediaService $mediaService) {}

    /**
     * @param  array<string, mixed>  $payload  Twilio webhook-shaped payload (From, To, Body, MessageSid, NumMedia, MediaUrl{N}, MediaContentType{N})
     */
    public function process(array $payload): string
    {
        $messageSid = (string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? '');

        if ($messageSid !== '' && $this->alreadyStored($messageSid)) {
            Log::info('Twilio inbound duplicate ignored', ['sid' => $messageSid]);

            return 'duplicate';
        }

        $this->forwardToPlusThis($payload);

        $fromRaw = $payload['From'] ?? '';
        $toRaw = $payload['To'] ?? '';
        $bodyRaw = $payload['Body'] ?? '';

        $from = is_array($fromRaw) ? implode(',', $fromRaw) : (string) $fromRaw;
        $to = is_array($toRaw) ? implode(',', $toRaw) : (string) $toRaw;
        $body = is_array($bodyRaw) ? implode(',', $bodyRaw) : (string) $bodyRaw;

        if ($from === '' || $to === '') {
            Log::warning('Twilio inbound missing From/To fields', ['payload' => $payload]);

            return 'missing_fields';
        }

        $isMms = isset($payload['NumMedia']) && (int) $payload['NumMedia'] > 0;
        $workOrderMessage = $this->getWorkOrderMessage($body, $from, $to);

        if ($workOrderMessage) {
            $type = $workOrderMessage->conversation_type ?? '';
            $workOrderId = $workOrderMessage->work_order_id ?? '';

            if (! $workOrderId || ! $type) {
                Log::info('Inbound matched a work-order thread but missing type/id.');

                return 'unmatched';
            }

            if ($this->isInternalMirrorOfRecentOutbound(
                from: $from,
                to: $to,
                body: $body,
                workOrderId: (int) $workOrderId,
                conversationType: (string) $type,
                incomingSid: $messageSid !== '' ? $messageSid : null
            )) {
                return 'mirror';
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
                    $this->processMediaAttachments($conversation->id, $payload);
                }

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

                Log::info('Inbound work-order message stored.', ['conversation_id' => $conversation->id, 'sid' => $messageSid]);

                return 'work_order';
            } catch (Exception $e) {
                Log::error('Failed to create work-order conversation: '.$e->getMessage());

                return 'unmatched';
            }
        }

        $jobberMessage = $this->getJobberMessage($from, $to);

        if (! $jobberMessage) {
            Log::info('Inbound did not match any work-order or jobber thread.', ['from' => $from, 'to' => $to]);

            return 'unmatched';
        }

        $numMedia = (int) ($payload['NumMedia'] ?? 0);
        $hasVisitColumn = JobberTextMessage::hasVisitColumn();
        $hasTwilioSidColumn = \Schema::hasColumn('jobber_text_messages', 'twilio_sid');

        $createPayload = [
            'sender_number' => $from,
            'receiver_number' => $to,
            'messages' => $body,
            'image' => $numMedia > 0 ? ($payload['MediaUrl0'] ?? null) : null,
            'jobber_id' => $jobberMessage->jobber_id,
        ];

        if ($hasVisitColumn) {
            $createPayload['jobber_visit_id'] = $jobberMessage->jobber_visit_id ?? null;
        }

        if ($hasTwilioSidColumn && $messageSid !== '') {
            $createPayload['twilio_sid'] = $messageSid;
        }

        $textMessage = JobberTextMessage::create($createPayload);

        if ($numMedia > 1) {
            $extraRows = [];
            for ($i = 1; $i < $numMedia; $i++) {
                $row = [
                    'sender_number' => $from,
                    'receiver_number' => $to,
                    'messages' => '',
                    'image' => $payload["MediaUrl{$i}"] ?? null,
                    'jobber_id' => $jobberMessage->jobber_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasVisitColumn) {
                    $row['jobber_visit_id'] = $jobberMessage->jobber_visit_id ?? null;
                }

                $extraRows[] = $row;
            }

            if (! empty($extraRows)) {
                DB::table('jobber_text_messages')->insert($extraRows);
            }
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

        Log::info('Inbound jobber message stored.', ['jobber_text_message_id' => $textMessage->id, 'sid' => $messageSid]);

        return 'jobber';
    }

    protected function alreadyStored(string $sid): bool
    {
        if (Conversation::where('twilio_sid', $sid)->exists()) {
            return true;
        }

        if (\Schema::hasColumn('jobber_text_messages', 'twilio_sid')) {
            if (JobberTextMessage::where('twilio_sid', $sid)->exists()) {
                return true;
            }
        }

        return false;
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

    protected function forwardToPlusThis(array $data): void
    {
        try {
            $forwardUrl = 'https://e.plusthis.com/webhooks/Twilio/sms/19802';

            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post($forwardUrl, $data);

            if ($response->successful()) {
                Log::info('Message forwarded successfully to PlusThis');
            } else {
                Log::error('Failed to forward data to PlusThis. Response: '.$response->body());
            }
        } catch (Exception $e) {
            Log::error('Error forwarding data to PlusThis: '.$e->getMessage());
        }
    }

    protected function processMediaAttachments(int $conversationId, array $data): void
    {
        try {
            $numMedia = (int) ($data['NumMedia'] ?? 0);
            for ($i = 0; $i < $numMedia; $i++) {
                $url = $data["MediaUrl{$i}"] ?? null;
                $contentType = $data["MediaContentType{$i}"] ?? null;

                if (! $url || ! $contentType) {
                    continue;
                }

                $this->mediaService->downloadAndStore($url, $contentType, $conversationId);
            }
        } catch (Exception $e) {
            Log::error('Media attachment failed: '.$e->getMessage());
        }
    }

    protected function getWorkOrderMessage(string $body, string $from, string $to)
    {
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
                Log::warning('Twilio inbound reference did not match a work order', [
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
            ->first();
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
}
