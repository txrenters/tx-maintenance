<?php

namespace App\Services;

/**
 * The thread an inbound Twilio message belongs to, as resolved by
 * InboundTwilioMessageProcessor::resolveInboundThread().
 *
 * This replaces the older "return an existing Conversation row and copy two
 * attributes off it" contract, which could not express the reference case —
 * where the body names a work order that has no matching conversation row yet —
 * and risked leaking unrelated attributes (twilio_sid, is_mms) onto the new row.
 */
final class InboundThreadMatch
{
    /**
     * @param  string  $strategy  Which resolution tier won: ref, ref_party, open_outbound, any_outbound, either_direction, legacy_exact.
     * @param  array<int, int>  $candidateWorkOrderIds  Every work order the phone pair could have meant; more than one means the routing was a judgement call.
     */
    public function __construct(
        public readonly int $workOrderId,
        public readonly string $conversationType,
        public readonly string $strategy,
        public readonly array $candidateWorkOrderIds = [],
    ) {}

    /**
     * True when the phone pair matched more than one work order, so this route
     * was chosen by recency rather than known for certain.
     */
    public function isAmbiguous(): bool
    {
        return count($this->candidateWorkOrderIds) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'strategy' => $this->strategy,
            'work_order_id' => $this->workOrderId,
            'conversation_type' => $this->conversationType,
            'candidate_work_order_ids' => $this->candidateWorkOrderIds,
        ];
    }
}
