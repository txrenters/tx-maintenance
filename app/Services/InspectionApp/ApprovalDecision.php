<?php

namespace App\Services\InspectionApp;

/**
 * What the approval filter decided for one work order: send it (and under
 * which rule), or hold it (and why). needsReading is true when the only
 * thing that could still let it go is the AI reading the owner's messages.
 */
final class ApprovalDecision
{
    public function __construct(
        public readonly bool $send,
        public readonly ?string $rule,
        public readonly ?string $heldReason,
        public readonly ?float $estimate,
        public readonly float $threshold,
        public readonly bool $needsReading = false,
    ) {}

    public static function send(string $rule, ?float $estimate, float $threshold): self
    {
        return new self(true, $rule, null, $estimate, $threshold);
    }

    public static function hold(string $reason, ?float $estimate, float $threshold, bool $needsReading): self
    {
        return new self(false, null, $reason, $estimate, $threshold, $needsReading);
    }
}
