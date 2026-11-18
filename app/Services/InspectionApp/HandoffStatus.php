<?php

namespace App\Services\InspectionApp;

use App\Models\InspectionAppHandoff;
use App\Models\WorkOrder;

/**
 * Where a work order's hand-off to the inspection app stands, for the work
 * order page: held and why, or sent and under which rule, with the AI's
 * reading when there is one.
 */
class HandoffStatus
{
    /**
     * @return array{status: string, rule: ?string, held_reason: ?string, sent_at: ?string, reading: ?array}|null
     */
    public function for(WorkOrder $workOrder): ?array
    {
        $row = InspectionAppHandoff::query()->with('aiInsight')->where('work_order_id', $workOrder->id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'status' => $row->status,
            'rule' => $row->rule,
            'held_reason' => $row->held_reason,
            'sent_at' => $row->sent_at?->toJSON(),
            'reading' => $row->aiInsight === null ? null : OwnerApprovalReader::summarize($row->aiInsight),
        ];
    }
}
