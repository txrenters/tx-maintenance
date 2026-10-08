<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\TaskAutoCompleteService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The sweep behind TaskAutoCompleteService: every open THMP work order with
 * a pending task that carries a trigger. Runs every ten minutes, which is
 * how a Jobber completion webhook or a photo the note sync just pulled turns
 * into a tick without its own hook. A schedule saved in the app ticks at
 * once through ServiceScheduleController.
 *
 * --dry-run prints what would be ticked and writes nothing: the production
 * proof before the gate goes on. --work-order takes the LOCAL id.
 */
class AutoCompleteThmpTasks extends Command
{
    protected $signature = 'tasks:auto-complete-thmp
        {--dry-run : Print what would be ticked and write nothing}
        {--work-order= : One work order id, instead of every open THMP one}
        {--limit=500 : How many work orders to check in a run}';

    protected $description = 'Tick THMP checklist tasks whose proof is in (schedule saved, tenant texted, Jobber job done, before/after photos). Never moves the service status.';

    public function handle(TaskAutoCompleteService $service): int
    {
        if (! TaskAutoCompleteService::isEnabled()) {
            $this->warn('Task auto-complete is off (TASK_AUTO_COMPLETE_ENABLED). Nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $tasks = 0;
        $workOrders = 0;

        foreach ($this->candidates() as $workOrder) {
            $hits = $service->evaluate($workOrder);

            if ($hits->isEmpty()) {
                continue;
            }

            $label = '#'.($workOrder->work_order_no ?? $workOrder->id);

            foreach ($hits as $hit) {
                $verb = $dryRun ? 'would tick' : 'ticked';
                $this->line("{$label}: {$verb} \"{$hit['task']->description}\" — {$hit['reason']}.");
            }

            $count = $dryRun ? $hits->count() : $service->run($workOrder);

            if ($count > 0) {
                $tasks += $count;
                $workOrders++;
            }
        }

        $summary = $dryRun
            ? "Would tick {$tasks} task(s) on {$workOrders} work order(s)."
            : "{$tasks} task(s) ticked on {$workOrders} work order(s).";

        $this->info($summary);

        if (! $dryRun) {
            Log::info('THMP task auto-complete sweep finished.', ['tasks' => $tasks, 'work_orders' => $workOrders]);
        }

        return self::SUCCESS;
    }

    /**
     * Open THMP work orders holding a pending template task with a trigger
     * that the system has not ticked before. Newest first, so a long backlog
     * reaches the work orders people are looking at.
     *
     * @return Collection<int, WorkOrder>
     */
    private function candidates(): Collection
    {
        $one = $this->option('work-order');

        if (filled($one)) {
            return WorkOrder::query()->whereKey((int) $one)->get();
        }

        return WorkOrder::query()
            ->whereNotIn('status', WorkOrder::CLOSED_STATUSES)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('jobber_job_gid')
                ->orWhereHas('vendors', fn (Builder $vendors) => $vendors
                    ->whereRaw('LOWER(TRIM(vendors.name)) = ?', [Str::lower(trim(Vendor::THMP_NAME))])))
            ->whereHas('tasks', fn (Builder $tasks) => $tasks
                ->where('status', '!=', 'completed')
                ->whereNull('auto_completed_at')
                ->whereHas('task', fn (Builder $template) => $template->whereNotNull('auto_complete_trigger')))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }
}
