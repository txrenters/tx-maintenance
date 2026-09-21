<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\JobberNoteSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Copies the THMP crew's Jobber notes onto the work orders they belong to.
 *
 * Jobber publishes no note webhook, so this polls. Only work orders linked to
 * a Jobber job are candidates, which is a small set: the link is written when
 * THMP is assigned.
 *
 * Run with --work-order to try a single one by hand, which is how the OAuth
 * scope gets proven on production before the schedule is turned on.
 */
class SyncJobberJobNotes extends Command
{
    protected $signature = 'jobber:sync-job-notes
        {--work-order= : One work order id, instead of every linked one}
        {--limit=200 : How many work orders to walk in a run}';

    protected $description = 'Copy notes and photos from Jobber jobs onto their work orders';

    public function handle(JobberNoteSyncService $notes): int
    {
        if (! config('services.jobber.note_sync_enabled')) {
            $this->warn('Jobber note sync is off (JOBBER_NOTE_SYNC_ENABLED). Nothing to do.');

            return self::SUCCESS;
        }

        $workOrders = $this->candidates();

        if ($workOrders->isEmpty()) {
            $this->info('No work orders are linked to a Jobber job.');

            return self::SUCCESS;
        }

        $synced = 0;
        $skipped = 0;

        foreach ($workOrders as $workOrder) {
            $count = $notes->syncWorkOrder($workOrder);

            if ($count === null) {
                $skipped++;

                continue;
            }

            $synced++;
            $this->line("Work order #{$workOrder->work_order_no}: {$count} note(s).");
        }

        $this->info("Synced {$synced} work order(s); {$skipped} had no job or could not be read.");
        Log::info('Jobber note sync finished', ['synced' => $synced, 'skipped' => $skipped]);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    private function candidates(): Collection
    {
        $one = $this->option('work-order');

        if (filled($one)) {
            return WorkOrder::query()->whereKey((int) $one)->get();
        }

        // Newest first: a coordinator is far likelier to be looking at a
        // recent work order than one closed months ago.
        return WorkOrder::query()
            ->whereNotNull('jobber_job_gid')
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get();
    }
}
