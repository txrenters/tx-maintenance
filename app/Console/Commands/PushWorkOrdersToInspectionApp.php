<?php

namespace App\Console\Commands;

use App\Models\InspectionAppHandoff;
use App\Services\InspectionApp\HandoffService;
use Illuminate\Console\Command;

/**
 * The sweep behind the inspection app hand-off: queue every outbox row that
 * never got through and every work order that changed since the app last
 * saw it. Scheduled every five minutes; the real-time path is
 * HandoffService::consider() at the moment a vendor is assigned, so a
 * healthy run usually finds nothing.
 *
 *   --dry-run         report what would be queued, queue nothing
 *   --work-order=N    hand work order N off right now, synchronously
 */
class PushWorkOrdersToInspectionApp extends Command
{
    protected $signature = 'inspection-app:push-work-orders
        {--dry-run : Report what would be queued without queuing anything}
        {--work-order= : Hand this one work order (by number) off right now}';

    protected $description = 'Queue work orders for our own crews to the technicians\' inspection app';

    public function handle(HandoffService $service): int
    {
        if (! $service->enabled()) {
            $this->warn('Inspection app hand-off is off: set INSPECTION_APP_HANDOFF_ENABLED, INSPECTION_APP_URL and INSPECTION_APP_TOKEN.');

            return self::SUCCESS;
        }

        if ($this->option('work-order') !== null) {
            return $this->pushOne((int) $this->option('work-order'), $service);
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $service->sweep($dryRun);

        $this->info(sprintf(
            '%d checked, %d %s, %d unchanged; %d held, %d read by AI, %d %s.',
            $result['checked'],
            $result['queued'],
            $dryRun ? 'would be queued' : 'queued',
            $result['unchanged'],
            $result['held'],
            $result['read'],
            $result['released'],
            $dryRun ? 'would be released' : 'released',
        ));

        return self::SUCCESS;
    }

    private function pushOne(int $workOrderNo, HandoffService $service): int
    {
        $row = $service->pushNow($workOrderNo);

        if (! $row) {
            $this->warn(sprintf('Work order %d: not found, or none of our vendors is on it.', $workOrderNo));

            return self::FAILURE;
        }

        $line = sprintf('Work order %d: %s', $workOrderNo, $row->status);

        if ($row->status === InspectionAppHandoff::STATUS_SENT) {
            $this->info($line.($row->external_id ? ' ('.$row->external_id.')' : ''));

            return self::SUCCESS;
        }

        $this->warn($line.($row->last_error ? ' - '.$row->last_error : ''));

        return self::FAILURE;
    }
}
