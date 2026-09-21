<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\JobberNoteSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes one work order's Jobber notes off the schedule.
 *
 * Dispatched when someone opens a work order's Notes tab, so a note written
 * in Jobber a minute ago does not wait for the half-hourly poll. Queued and
 * never inline: a throttled Jobber call can pause for up to a minute, which
 * would hang the tab on a request that had nothing to show for it anyway.
 *
 * Two tries. If Jobber is throttling or down, the scheduled run picks this up
 * shortly; a retry storm over a convenience refresh helps nobody.
 */
class SyncJobberJobNotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(private int $workOrderId) {}

    public function handle(JobberNoteSyncService $notes): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);

        if ($workOrder === null) {
            return;
        }

        $notes->syncWorkOrder($workOrder);
    }
}
