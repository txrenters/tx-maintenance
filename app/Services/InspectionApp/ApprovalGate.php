<?php

namespace App\Services\InspectionApp;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * May a crew start this work order? The office's rule, applied to every
 * work order the hand-off considers (docs/superpowers/specs/
 * 2026-11-19-work-order-handoff-approval-filter-design.md):
 *
 *   0. An emergency goes, whatever it costs, quoted or not.
 *   1. An approval on record (approved_by / approved_date, synced from
 *      PropertyWare) goes, quoted or not ("if it is approve move forward",
 *      the office, 2026-11-21).
 *   2. No estimate from the crew yet: it waits for one ("Hold until there's
 *      an estimate", the office, 2026-11-20). Nothing to approve, so the AI
 *      does not read it; the crew's estimate releases it (HandoffService is
 *      called when it is saved, and the sweep checks every five minutes).
 *   3. A $0 estimate with no approval waits ("if estimation is zero but no
 *      approve do not forward", the office, 2026-11-21).
 *   4. $1 to $300 goes: the office does not ask the owner.
 *   5. Over it ($301 and up), it waits for the AI's reading of the owner's
 *      messages (OwnerApprovalReader), and says why it is waiting.
 *
 * The estimate is our crew's own, entered on the work order in the vendor
 * portal or the Vendor Edit tab. PropertyWare's estimate is left out -- it is
 * $0 on every work order -- and so is an outside vendor's quote on the same
 * work order, which is not the crew's job.
 */
class ApprovalGate
{
    /** What PropertyWare syncs into approved_by when nobody has. */
    private const NOT_AN_APPROVER = ['', 'n/a', 'na', 'none', 'null', '-'];

    /** The held reason, and the hand-off card's words, while the crew has not quoted. */
    public const WAITING_FOR_THE_ESTIMATE = "Waiting for the crew's estimate";

    /** The held reason for a $0 estimate with no approval on record. */
    public const ZERO_ESTIMATE_NOT_APPROVED = 'Estimate is $0, and no owner approval is recorded';

    public function __construct(private OwnerApprovalReader $reader, private HandoffRule $rule) {}

    public function threshold(): float
    {
        return max(0.0, (float) config('services.inspection_app.handoff_approval_threshold', 300));
    }

    public function estimate(WorkOrder $workOrder): ?float
    {
        $vendors = $workOrder->relationLoaded('vendors') ? $workOrder->vendors : $workOrder->vendors()->get();

        return $vendors
            ->filter(fn (Vendor $vendor): bool => $this->rule->isInHouse($vendor))
            ->map(fn (Vendor $vendor): ?float => $this->amount($vendor->pivot?->cost_estimate))
            ->filter(fn (?float $amount): bool => $amount !== null)
            ->max();
    }

    public function approvalRecorded(WorkOrder $workOrder): bool
    {
        $by = strtolower(trim((string) $workOrder->approved_by));

        if ($by !== '' && ! in_array($by, self::NOT_AN_APPROVER, true)) {
            return true;
        }

        return $workOrder->approved_date !== null && trim((string) $workOrder->approved_date) !== '';
    }

    public function decide(WorkOrder $workOrder, ?AiInsight $reading = null): ApprovalDecision
    {
        $threshold = $this->threshold();
        $estimate = $this->estimate($workOrder);

        // A boolean in the schema, but a row read back carries 1, not true.
        if ((bool) $workOrder->is_emergency) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_EMERGENCY, $estimate, $threshold);
        }

        if ($this->approvalRecorded($workOrder)) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_OWNER_APPROVED_RECORDED, $estimate, $threshold);
        }

        // Sent approved or not (handoff_send_all_since): the inspection app
        // decides. Nothing below runs, so the AI does not read the owner's
        // messages for it either.
        if ($this->sendsAll($workOrder)) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_SENT_UNAPPROVED, $estimate, $threshold);
        }

        if ($estimate === null) {
            return ApprovalDecision::hold(self::WAITING_FOR_THE_ESTIMATE, null, $threshold, false);
        }

        if ($estimate <= 0.0) {
            return ApprovalDecision::hold(self::ZERO_ESTIMATE_NOT_APPROVED, $estimate, $threshold, false);
        }

        if ($estimate <= $threshold) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_UNDER_THRESHOLD, $estimate, $threshold);
        }

        // The reader's switch is a kill switch: off, a stored reading sends
        // nothing either, and the work order waits for the approval fields.
        $reading = $this->reader->enabled() ? ($reading ?? $this->reader->latestFor($workOrder)) : null;

        if ($reading !== null && $this->reader->approves($reading)) {
            if ((bool) config('services.inspection_app.handoff_ai_requires_confirmation', false)) {
                return ApprovalDecision::hold(
                    sprintf('Owner approved in messages (%d%%): %s — enter the approval in PropertyWare to send', (int) $reading->confidence, Str::limit((string) data_get($reading->data, 'summary'), 90)),
                    $estimate,
                    $threshold,
                    false,
                );
            }

            return ApprovalDecision::send(InspectionAppHandoff::RULE_OWNER_APPROVED_IN_MESSAGES, $estimate, $threshold);
        }

        $reason = $this->heldReason($estimate, $threshold);

        if ($reading !== null) {
            $count = (int) data_get($reading->data, 'messages_read', 0);
            $reason .= sprintf(
                ' · AI read %d owner message%s and found %s (%d%%)',
                $count,
                $count === 1 ? '' : 's',
                data_get($reading->data, 'valid') ? 'no approval' : 'nothing it could use',
                (int) $reading->confidence,
            );
        }

        $needsReading = $this->reader->enabled() && ($reading === null || $this->reader->isStale($workOrder, $reading));

        return ApprovalDecision::hold(Str::limit($reason, 200, ''), $estimate, $threshold, $needsReading);
    }

    /**
     * Whether this work order goes across approved or not: created on or after
     * handoff_send_all_since. Unset, or a work order from before it, keeps the
     * filter, so switching it on never backfills.
     */
    public function sendsAll(WorkOrder $workOrder): bool
    {
        $since = trim((string) config('services.inspection_app.handoff_send_all_since', ''));

        if ($since === '') {
            return false;
        }

        $created = $workOrder->created_date ?? $workOrder->created_at;

        if ($created === null) {
            return false;
        }

        return ! Carbon::parse((string) $created)->lt(Carbon::parse($since)->startOfDay());
    }

    private function heldReason(?float $estimate, float $threshold): string
    {
        $limit = '$'.number_format($threshold, $threshold == floor($threshold) ? 0 : 2);

        return $estimate === null
            ? 'No estimate on the work order, and no owner approval is recorded'
            : sprintf('Estimate $%s is over %s, and no owner approval is recorded', number_format($estimate, 2), $limit);
    }

    /** A crew's typed amount: blank is "not quoted yet", $0 is a quote. */
    private function amount(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return max(0.0, (float) $value);
    }
}
