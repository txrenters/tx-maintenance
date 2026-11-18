<?php

namespace App\Console\Commands;

use App\Models\InspectionAppHandoff;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The hand-off outbox, as a table. --rule=owner_approved_in_messages is the
 * office's after-the-fact look at what the AI sent on its own reading, with
 * the reading beside each.
 */
class ListInspectionAppHandoffs extends Command
{
    protected $signature = 'inspection-app:handoffs
        {--rule= : Only rows let through by this rule (emergency, under_threshold, owner_approved_recorded, owner_approved_in_messages, manual)}
        {--status= : Only rows in this status (pending, sent, failed, skipped, held)}
        {--limit=50 : How many, newest first}';

    protected $description = 'List work orders handed (or held) for the technicians\' inspection app';

    public function handle(): int
    {
        $rows = InspectionAppHandoff::query()
            ->with(['workOrder', 'aiInsight'])
            ->when($this->option('rule'), fn ($query, $rule) => $query->where('rule', $rule))
            ->when($this->option('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['WO#', 'Status', 'Rule', 'Estimate', 'AI', 'Reading / held reason', 'When'],
            $rows->map(function (InspectionAppHandoff $row): array {
                $reading = $row->aiInsight === null ? null : OwnerApprovalReader::summarize($row->aiInsight);

                return [
                    $row->workOrder?->work_order_no ?? $row->work_order_id,
                    $row->status,
                    $row->rule ?? '—',
                    $row->workOrder?->cost_estimate === null ? '—' : '$'.number_format((float) $row->workOrder->cost_estimate, 2),
                    $reading === null ? '—' : $reading['confidence'].'%',
                    Str::limit($row->status === InspectionAppHandoff::STATUS_HELD ? (string) $row->held_reason : (string) ($reading['summary'] ?? ''), 90),
                    ($row->sent_at ?? $row->updated_at)?->format('Y-m-d H:i') ?? '',
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
