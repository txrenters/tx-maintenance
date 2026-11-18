<?php

namespace App\Services\InspectionApp;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\Vendor;
use App\Models\WorkOrder;

/**
 * May a crew start this work order? The office's rule, applied to every
 * work order the hand-off considers (docs/superpowers/specs/
 * 2026-11-19-work-order-handoff-approval-filter-design.md):
 *
 *   0. An emergency goes, whatever it costs.
 *   1. Under the threshold ($300) goes: the office does not ask the owner.
 *   2. At or over it, an approval on record (approved_by / approved_date,
 *      synced from PropertyWare) goes.
 *   3. Otherwise it waits for the AI's reading of the owner's messages
 *      (OwnerApprovalReader), and says why it is waiting.
 *
 * The estimate is the work order's own; the crew's estimate on the vendor
 * pivot stands in when the work order has none. Zero means "not set" the way
 * PropertyWare sends it, so it counts as no estimate, never as cheap.
 */
class ApprovalGate
{
    /** What PropertyWare syncs into approved_by when nobody has. */
    private const NOT_AN_APPROVER = ['', 'n/a', 'na', 'none', 'null', '-'];

    public function threshold(): float
    {
        return max(0.0, (float) config('services.inspection_app.handoff_approval_threshold', 300));
    }

    public function estimate(WorkOrder $workOrder): ?float
    {
        $own = $this->positive($workOrder->cost_estimate);

        if ($own !== null) {
            return $own;
        }

        $vendors = $workOrder->relationLoaded('vendors') ? $workOrder->vendors : $workOrder->vendors()->get();

        return $vendors
            ->map(fn (Vendor $vendor): ?float => $this->positive($vendor->pivot?->cost_estimate))
            ->filter()
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

        if ($workOrder->is_emergency === true) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_EMERGENCY, $estimate, $threshold);
        }

        if ($estimate !== null && $estimate < $threshold) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_UNDER_THRESHOLD, $estimate, $threshold);
        }

        if ($this->approvalRecorded($workOrder)) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_OWNER_APPROVED_RECORDED, $estimate, $threshold);
        }

        return ApprovalDecision::hold($this->heldReason($estimate, $threshold), $estimate, $threshold, true);
    }

    private function heldReason(?float $estimate, float $threshold): string
    {
        $limit = '$'.number_format($threshold, $threshold == floor($threshold) ? 0 : 2);

        return $estimate === null
            ? 'No estimate on the work order, and no owner approval is recorded'
            : sprintf('Estimate $%s is over %s, and no owner approval is recorded', number_format($estimate, 2), $limit);
    }

    private function positive(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }
}
