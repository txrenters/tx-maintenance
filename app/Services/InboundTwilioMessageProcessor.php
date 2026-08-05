<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\Scopes\ConversationScope;
use App\Models\TwilioPhoneNumber;
use App\Models\WorkOrder;
use Exception;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * How many recent messages on a phone pair to weigh when routing. Only the
     * newest is chosen; the rest are read to tell whether the pair is ambiguous.
     */
    protected const CANDIDATE_LIMIT = 25;

    public function __construct(
        protected MediaService $mediaService,
        protected TenantPhotoMirrorService $photoMirror
    ) {}

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
        $match = $this->resolveInboundThread($body, $from, $to);

        if ($match) {
            $type = $match->conversationType;
            $workOrderId = $match->workOrderId;

            if ($this->isInternalMirrorOfRecentOutbound(
                from: $from,
                to: $to,
                body: $body,
                workOrderId: $workOrderId,
                conversationType: $type,
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

                    // Photos a tenant texts in belong on the Attachments tab
                    // too, not only inside the message thread. Never fatal.
                    if ($type === 'tenant') {
                        $this->photoMirror->mirrorForConversation($conversation, $conversation->media()->get());
                    }
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

    /**
     * Decide which work order thread an inbound message belongs to.
     *
     * The outbound "from" number belongs to a coordinator, not to a work order,
     * so a tenant with two open work orders under the same coordinator produces
     * an identical phone pair on both. The phone pair alone therefore cannot
     * answer this, and the tiers below go from most to least certain:
     *
     *   0. an explicit (Ref: WO#123) footer quoted back to us
     *   1. the last message we sent this person about an OPEN work order
     *   2. the same, allowing closed work orders
     *   3. any message on this phone pair, either direction
     *   4. today's exact-string pair match, so nothing that is currently stored
     *      can start being dropped
     *
     * Public because the Twilio message search page imports messages by hand and
     * must land them on the same thread the webhook would have chosen.
     */
    public function resolveInboundThread(string $body, string $from, string $to): ?InboundThreadMatch
    {
        $fromDigits = Conversation::lastTenDigits($from);
        $toDigits = Conversation::lastTenDigits($to);

        // Short codes, alphanumeric sender IDs and the literal 'portal' sender
        // written by TenantPortalController reduce to null. Those have never
        // matched on digits, so leave them on the legacy exact-string path.
        $match = ($fromDigits === null || $toDigits === null)
            ? $this->matchByLegacyExactPair($from, $to)
            : $this->matchByReference($body, $fromDigits, $toDigits, $from, $to)
                ?? $this->matchByRecentOutbound($fromDigits, $toDigits, true, $from, $to)
                ?? $this->matchByRecentOutbound($fromDigits, $toDigits, false, $from, $to)
                ?? $this->matchByEitherDirection($fromDigits, $toDigits)
                ?? $this->matchByLegacyExactPair($from, $to);

        if ($match) {
            Log::info('Inbound SMS routed to work-order thread', $match->logContext() + [
                'from' => $from,
                'to' => $to,
            ]);
        }

        return $match;
    }

    /**
     * Tier 0 — an explicit reference in the body names the work order outright.
     *
     * Senders build the token as work_order_no ?? id, so both are looked up. The
     * whereNull guard on the id fallback stops a work order whose id is 4312
     * from hijacking one whose work_order_no is 4312.
     *
     * A reference identifies the work order only, never the thread: owner
     * messages carry the same footer as tenant ones. The conversation type is
     * still resolved from the phone pair, then from the work order's own
     * parties.
     */
    protected function matchByReference(string $body, string $fromDigits, string $toDigits, string $from, string $to): ?InboundThreadMatch
    {
        if (! preg_match('/Ref:\s*WO#\s*(\d+)/i', $body, $matches)) {
            return null;
        }

        $reference = (int) $matches[1];

        $workOrder = WorkOrder::query()->where('work_order_no', $reference)->orderByDesc('id')->first()
            ?? WorkOrder::query()->whereKey($reference)->whereNull('work_order_no')->first();

        if (! $workOrder) {
            Log::warning('Twilio inbound reference did not match a work order', [
                'reference' => $reference,
                'from' => $from,
                'to' => $to,
            ]);

            return null;
        }

        $thread = $this->matchThreadOnWorkOrder($workOrder->id, $fromDigits, $toDigits);

        if ($thread) {
            return new InboundThreadMatch($workOrder->id, $thread, 'ref', [$workOrder->id]);
        }

        $party = $this->matchByPartyOnWorkOrder($workOrder, $fromDigits);

        return $party
            ? new InboundThreadMatch($workOrder->id, $party, 'ref_party', [$workOrder->id])
            : null;
    }

    /**
     * The conversation type of the most recent usable message on one work order
     * that involves this phone pair — preferring messages we sent to them over
     * messages in either direction.
     */
    protected function matchThreadOnWorkOrder(int $workOrderId, string $fromDigits, string $toDigits): ?string
    {
        $outbound = $this->whereSentTo(
            $this->usableThreadQuery()->where('work_order_id', $workOrderId),
            $fromDigits,
            $toDigits
        )->first();

        if ($outbound) {
            return (string) $outbound->conversation_type;
        }

        $either = $this->usableThreadQuery()
            ->where('work_order_id', $workOrderId)
            ->where(fn (Builder $pair) => $this->whereEitherDirection($pair, $fromDigits, $toDigits))
            ->first();

        return $either ? (string) $either->conversation_type : null;
    }

    /**
     * Infer the thread from the work order's own parties, for a referenced work
     * order that has no conversation history matching this number yet.
     */
    protected function matchByPartyOnWorkOrder(WorkOrder $workOrder, string $fromDigits): ?string
    {
        $tenant = $workOrder->requested_by;
        $tenantNumber = filled($tenant?->mobile_phone) ? $tenant->mobile_phone : $tenant?->home_phone;

        if ($tenantNumber && Conversation::lastTenDigits((string) $tenantNumber) === $fromDigits) {
            return 'tenant';
        }

        foreach ($workOrder->owners as $owner) {
            if (Conversation::lastTenDigits($workOrder->normalizedOwnerPhone($owner)) === $fromDigits) {
                return 'owner';
            }
        }

        foreach ($workOrder->vendors as $vendor) {
            foreach ([$vendor->twilio_number, $vendor->user?->phone] as $vendorNumber) {
                if ($vendorNumber && Conversation::lastTenDigits((string) $vendorNumber) === $fromDigits) {
                    return 'vendor';
                }
            }
        }

        return null;
    }

    /**
     * Tiers 1 and 2 — the thread we most recently sent a message to on this
     * phone pair, optionally restricted to open work orders.
     *
     * Deliberately outbound-only. Ranking by activity in either direction lets a
     * misroute reinforce itself, because the wrongly filed inbound message
     * becomes the newest row and captures every later reply. Anchoring on what
     * we last chose to send keeps an error from compounding.
     */
    protected function matchByRecentOutbound(string $fromDigits, string $toDigits, bool $openOnly, string $from, string $to): ?InboundThreadMatch
    {
        $query = $this->whereSentTo($this->usableThreadQuery(), $fromDigits, $toDigits)
            ->select('work_order_conversations.*')
            ->limit(self::CANDIDATE_LIMIT);

        if ($openOnly) {
            // An explicit join rather than whereHas, so WorkOrderScope can never
            // narrow inbound routing based on who happens to be authenticated.
            $query->join('work_orders', 'work_orders.id', '=', 'work_order_conversations.work_order_id')
                ->where('work_orders.status', 'Open');
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $candidateIds = $rows->pluck('work_order_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $winner = $rows->first();

        $match = new InboundThreadMatch(
            workOrderId: (int) $winner->work_order_id,
            conversationType: (string) $winner->conversation_type,
            strategy: $openOnly ? 'open_outbound' : 'any_outbound',
            candidateWorkOrderIds: $candidateIds,
        );

        if ($match->isAmbiguous()) {
            Log::warning('Inbound SMS thread ambiguous — multiple work orders share this phone pair', $match->logContext() + [
                'from' => $from,
                'to' => $to,
                'open_only' => $openOnly,
            ]);
        }

        return $match;
    }

    /**
     * Tier 3 — any message on this phone pair, in either direction.
     */
    protected function matchByEitherDirection(string $fromDigits, string $toDigits): ?InboundThreadMatch
    {
        $row = $this->usableThreadQuery()
            ->where(fn (Builder $pair) => $this->whereEitherDirection($pair, $fromDigits, $toDigits))
            ->first();

        return $row
            ? new InboundThreadMatch((int) $row->work_order_id, (string) $row->conversation_type, 'either_direction', [(int) $row->work_order_id])
            : null;
    }

    /**
     * Constrain to messages we sent from $toDigits to $fromDigits.
     */
    protected function whereSentTo(Builder $query, string $fromDigits, string $toDigits): Builder
    {
        return $this->whereNumberEndsWith(
            $this->whereNumberEndsWith($query, 'sender_number', $toDigits),
            'receiver_number',
            $fromDigits
        );
    }

    /**
     * Constrain to messages between the two numbers, sent either way.
     */
    protected function whereEitherDirection(Builder $query, string $fromDigits, string $toDigits): Builder
    {
        return $query->where(fn (Builder $sent) => $this->whereSentTo($sent, $fromDigits, $toDigits))
            ->orWhere(fn (Builder $received) => $this->whereSentTo($received, $toDigits, $fromDigits));
    }

    /**
     * Match a stored phone column by its final ten digits.
     *
     * Stored numbers are whatever shape the writing code happened to use —
     * +12816999281, 1-281-699-9281, (281) 699-9281 — so the punctuation has to
     * come out in SQL before the suffix comparison. A plain suffix LIKE would
     * silently miss every punctuated row.
     */
    protected function whereNumberEndsWith(Builder $query, string $column, string $digits): Builder
    {
        $expression = 'work_order_conversations.'.$column;

        // Characters are a fixed literal set, and the column name is chosen by
        // the caller from this class only — nothing here comes from the payload.
        foreach ([' ', '(', ')', '-', '.', '+'] as $punctuation) {
            $expression = "REPLACE({$expression}, '{$punctuation}', '')";
        }

        return $query->whereRaw($expression.' LIKE ?', ['%'.$digits]);
    }

    /**
     * Tier 4 — the original exact-string pair match. Kept as the last resort so
     * that any message routed today still routes after this change, including
     * numbers stored in a shape that does not reduce to ten digits.
     */
    protected function matchByLegacyExactPair(string $from, string $to): ?InboundThreadMatch
    {
        $row = $this->usableThreadQuery()
            ->where(function (Builder $pair) use ($from, $to) {
                $pair->where(function (Builder $sent) use ($from, $to) {
                    $sent->where('receiver_number', $from)
                        ->where('sender_number', $to);
                })->orWhere(function (Builder $received) use ($from, $to) {
                    $received->where('receiver_number', $to)
                        ->where('sender_number', $from);
                });
            })
            ->first();

        return $row
            ? new InboundThreadMatch((int) $row->work_order_id, (string) $row->conversation_type, 'legacy_exact', [(int) $row->work_order_id])
            : null;
    }

    /**
     * Rows that can serve as a routing answer: attached to a work order, with a
     * usable conversation type, newest first and tie-broken deterministically.
     *
     * Filtering blank types here also closes a silent drop — process() used to
     * bail out with 'unmatched' when the winning row had no type, without even
     * trying the jobber path.
     */
    protected function usableThreadQuery(): Builder
    {
        // Columns are table-qualified because matchByRecentOutbound joins
        // work_orders, which carries its own created_at and id. The global scope
        // is dropped because where a message belongs is a fact about the message,
        // never about who happens to be looking — the search page resolves
        // threads through here while authenticated as staff.
        return Conversation::query()
            ->withoutGlobalScope(ConversationScope::class)
            ->whereNotNull('work_order_conversations.work_order_id')
            ->whereNotNull('work_order_conversations.conversation_type')
            ->where('work_order_conversations.conversation_type', '!=', '')
            ->orderByDesc('work_order_conversations.created_at')
            ->orderByDesc('work_order_conversations.id');
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
