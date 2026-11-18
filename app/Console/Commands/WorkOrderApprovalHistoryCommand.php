<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use App\Services\WorkOrderApprovalHistory;
use Illuminate\Console\Command;

class WorkOrderApprovalHistoryCommand extends Command
{
    protected $signature = 'work-orders:approval-history {work_order_no : The work order number, as shown on the board}';

    protected $description = 'Show every recorded change to a work order\'s approval flag, approver, date and comment';

    public function handle(): int
    {
        $workOrder = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->where('work_order_no', $this->argument('work_order_no'))
            ->first();

        if (! $workOrder) {
            $this->error('No work order with that number.');

            return self::FAILURE;
        }

        $rows = WorkOrderApprovalHistory::for($workOrder);

        if ($rows->isEmpty()) {
            $this->info('No approval history recorded for this work order yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['When', 'Event', 'Field', 'Old', 'New', 'By', 'Via'],
            $rows->flatMap(function ($row) {
                $old = $row->properties['old'] ?? [];

                return collect($row->properties['new'] ?? [])->map(fn ($value, $column) => [
                    $row->created_at?->toDateTimeString(),
                    $row->event,
                    $column,
                    $this->display($old[$column] ?? null),
                    $this->display($value),
                    $row->causer?->name ?? '',
                    $row->properties['via'] ?? '',
                ])->values();
            })->all(),
        );

        return self::SUCCESS;
    }

    private function display(mixed $value): string
    {
        if ($value === null) {
            return '(blank)';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string) $value;
    }
}
