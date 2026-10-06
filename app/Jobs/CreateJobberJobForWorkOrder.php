<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\JobberJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    /**
     * Retries spaced 5 min / 30 min / 2 h apart (overriding the worker's
     * instant --tries) so a Jobber outage or token reconnect window can pass
     * before the job gives up and lands in failed_jobs.
     */
    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [300, 1800, 7200];

    public function __construct(
        public int $workOrderId,
    ) {}

    public function handle(JobberJobService $jobber): void
    {
        if (! config('services.jobber.job_create_enabled')) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()
            ->with(['building', 'requested_by', 'outsideCustomer'])
            ->find($this->workOrderId);

        if (! $workOrder || filled($workOrder->jobber_job_gid)) {
            return;
        }

        // A Crystal Creek Air work order has no building and no tenant: its
        // Jobber job goes on the customer's own property under the Crystal
        // Creek Air client instead of a Texas Renters property.
        $result = $workOrder->isCrystalCreek()
            ? $jobber->createJobForOutsideCustomer($workOrder)
            : $jobber->createJobForWorkOrder($workOrder);

        if ($result === null) {
            return;
        }

        $workOrder->update([
            'jobber_job_gid' => $result['gid'],
            'jobber_web_uri' => $result['web_uri'],
        ]);
    }

    /**
     * All retries exhausted: the work order has no Jobber job and nothing will
     * try again on its own. Logged loudly; the job stays in failed_jobs where
     * the IT Tools page lists it for a manual retry after reconnecting.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Jobber job creation permanently failed for work order.', [
            'work_order_id' => $this->workOrderId,
            'error' => $exception->getMessage(),
        ]);
    }
}
