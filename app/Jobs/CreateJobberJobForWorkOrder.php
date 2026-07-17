<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\JobberJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * When THMP is assigned to a work order, create the matching Jobber job and
 * store its id + deep link on the work order (surfaced as "Open in Jobber").
 *
 * Gated by services.jobber.job_create_enabled so local/test environments never
 * create real Jobber jobs. Idempotent: a work order that already has a linked
 * job is skipped, so a re-assignment or retry never creates a duplicate.
 */
class CreateJobberJobForWorkOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workOrderId,
    ) {}

    public function handle(JobberJobService $jobber): void
    {
        if (! config('services.jobber.job_create_enabled')) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()
            ->with(['building', 'requested_by'])
            ->find($this->workOrderId);

        if (! $workOrder || filled($workOrder->jobber_job_gid)) {
            return;
        }

        $result = $jobber->createJobForWorkOrder($workOrder);

        if ($result === null) {
            return;
        }

        $workOrder->update([
            'jobber_job_gid' => $result['gid'],
            'jobber_web_uri' => $result['web_uri'],
        ]);
    }
}
