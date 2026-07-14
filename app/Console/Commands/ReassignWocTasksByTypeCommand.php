<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Illuminate\Console\Command;

/**
 * Backfill command. The per-type WOC routing in TaskService only affects tasks
 * created after it shipped; this reassigns the WOC tasks that already exist on
 * OPEN work orders of a routed type over to that type's dedicated coordinator.
 * Safe to re-run (already-routed tasks are skipped) and supports --dry-run.
 */
class ReassignWocTasksByTypeCommand extends Command
{
    protected $signature = 'tasks:reassign-woc-by-type
        {--dry-run : Report what would change without writing anything}
        {--include-completed : Also reassign completed tasks (default: pending only)}';

    protected $description = 'Reassign existing WOC tasks on open work orders to their per-type coordinator';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeCompleted = (bool) $this->option('include-completed');

        // Everyone currently holding the woc role — the assignees whose tasks we
        // treat as WOC coordination tasks eligible for re-routing.
        $wocUserIds = User::role('woc')->pluck('id');

        if ($wocUserIds->isEmpty()) {
            $this->warn('No users hold the woc role; nothing to reassign.');

            return self::SUCCESS;
        }

        $this->info($dryRun ? 'DRY RUN — no changes will be written.' : 'Reassigning WOC tasks…');
        $this->info($includeCompleted ? 'Scope: pending AND completed tasks.' : 'Scope: pending tasks only.');
        $this->newLine();

        $grandTotal = 0;

        foreach (TaskService::routedTypes() as $type) {
            $routedUser = TaskService::routedWocUserForType($type);

            if (! $routedUser) {
                $this->warn("• {$type}: routed user not found (missing account or wrong role) — skipped.");

                continue;
            }

            $openWorkOrderIds = WorkOrder::withoutGlobalScopes()
                ->where('status', 'Open')
                ->where('type', $type)
                ->pluck('id');

            if ($openWorkOrderIds->isEmpty()) {
                $this->line("• {$type}: no open work orders.");

                continue;
            }

            $taskIds = WorkOrderTask::withoutGlobalScopes()
                ->whereIn('work_order_id', $openWorkOrderIds)
                ->whereIn('assigned_user_id', $wocUserIds)
                ->where('assigned_user_id', '!=', $routedUser->id)
                ->when(! $includeCompleted, fn ($q) => $q->where('status', '!=', 'completed'))
                ->pluck('id');

            if ($taskIds->isEmpty()) {
                $this->line("• {$type}: already up to date (0 tasks).");

                continue;
            }

            $this->line("• {$type}: {$taskIds->count()} task(s) → {$routedUser->email} (role check passed).");
            $grandTotal += $taskIds->count();

            if (! $dryRun) {
                WorkOrderTask::withoutGlobalScopes()
                    ->whereIn('id', $taskIds)
                    ->update(['assigned_user_id' => $routedUser->id]);
            }
        }

        $this->newLine();
        $verb = $dryRun ? 'would be reassigned' : 'reassigned';
        $this->info("Done. {$grandTotal} task(s) {$verb}.");

        return self::SUCCESS;
    }
}
