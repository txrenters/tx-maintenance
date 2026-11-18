<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\InspectionApp\ApprovalGate;
use App\Services\InspectionApp\HandoffRule;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * How would the AI have read the old work orders, whose approval is already
 * known? For each closed work order of our crews over the threshold, the
 * record says whether the owner approved (approved_by / approved_date). The
 * reader is asked the same question from the notes and messages, and the
 * two are compared. Nothing is stored and nothing is sent: this is the
 * office looking before it trusts the reader with new work orders.
 *
 * Costs one AI call per work order; --limit keeps that in hand.
 */
class EvaluateOwnerApprovalReader extends Command
{
    protected $signature = 'inspection-app:evaluate-approval-reader
        {--since=2026-01-01 : Work orders created on or after this date}
        {--limit=25 : How many work orders to read (one AI call each)}
        {--work-order= : Read this one work order by number instead}
        {--json : Print the readings as JSON for a spreadsheet}';

    protected $description = 'Score the AI owner-approval reader against old work orders whose approval is on record';

    public function handle(HandoffRule $rule, ApprovalGate $gate, OwnerApprovalReader $reader): int
    {
        if (! $reader->enabled()) {
            $this->error('The reader is off or no AI provider is configured (INSPECTION_APP_HANDOFF_AI_READER_ENABLED, AI settings).');

            return self::FAILURE;
        }

        $limit = min(500, max(1, (int) $this->option('limit')));
        $workOrders = WorkOrder::withoutGlobalScopes()
            ->with('vendors')
            ->when($this->option('work-order'), fn ($query, $number) => $query->where('work_order_no', (int) $number))
            ->when(! $this->option('work-order'), fn ($query) => $query
                ->whereIn('status', WorkOrder::CLOSED_STATUSES)
                ->where('created_date', '>=', $this->option('since'))
                ->where(fn ($emergency) => $emergency->whereNull('is_emergency')->orWhere('is_emergency', false)))
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get()
            ->filter(fn (WorkOrder $workOrder): bool => $rule->qualifies($workOrder))
            ->filter(fn (WorkOrder $workOrder): bool => $this->option('work-order') || ($gate->estimate($workOrder) ?? INF) >= $gate->threshold())
            ->take($limit)
            ->values();

        if ($workOrders->isEmpty()) {
            $this->warn('No closed work orders of our crews over the threshold since '.$this->option('since').'.');

            return self::SUCCESS;
        }

        $rows = [];
        $agreed = 0;
        $falseSends = 0;
        $misses = 0;
        $unreadable = 0;

        foreach ($workOrders as $workOrder) {
            $recorded = $gate->approvalRecorded($workOrder);
            $reading = $reader->preview($workOrder);

            if ($reading === null) {
                $unreadable++;
                $rows[] = ['#'.$workOrder->work_order_no, $recorded ? 'approved' : 'no approval', 'AI unavailable', '', ''];

                continue;
            }

            $aiSends = $reading['approved'] && $reading['confidence'] >= $reader->minimumConfidence();
            $agreed += (int) ($aiSends === $recorded);
            $falseSends += (int) ($aiSends && ! $recorded);
            $misses += (int) (! $aiSends && $recorded);

            $rows[] = [
                '#'.$workOrder->work_order_no,
                $recorded ? 'approved' : 'no approval',
                $aiSends ? 'would send' : 'would hold',
                $reading['confidence'].'%',
                Str::limit($reading['error'] ?? $reading['summary'], 80),
            ];

            if ($this->option('json')) {
                $this->line(json_encode(['work_order_no' => $workOrder->work_order_no, 'recorded' => $recorded] + $reading, JSON_UNESCAPED_UNICODE));
            }
        }

        if (! $this->option('json')) {
            $this->table(['WO', 'On record', 'AI', 'Confidence', 'Summary'], $rows);
        }

        $scored = count($rows) - $unreadable;
        $this->info(sprintf('Agreed with the record: %d of %d.', $agreed, $scored));
        $this->info(sprintf('Would have sent without a recorded approval: %d. (Check these: an approval that only lives in messages is the point, a wrong one is the risk.)', $falseSends));
        $this->info(sprintf('Would have held an approved one: %d.', $misses));

        if ($unreadable > 0) {
            $this->warn(sprintf('%d could not be read (AI error).', $unreadable));
        }

        return self::SUCCESS;
    }
}
