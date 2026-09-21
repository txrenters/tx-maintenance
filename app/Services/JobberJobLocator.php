<?php

namespace App\Services;

use App\Models\Jobber;
use App\Models\WorkOrder;

/**
 * The Jobber job behind a work order.
 *
 * There is no foreign key between the two worlds, so the job is found by, in
 * order: the stored Jobber GID, the stored Jobber web URL, and finally the
 * "#<work order number>" suffix the app writes into every job title it
 * creates — the last of which is what finds jobs made before this app owned
 * job creation.
 *
 * Extracted so the note sync and JobberTechnicianResolver resolve a work
 * order the same way; two copies of this cascade would drift the moment one
 * of them learned about a new kind of link.
 */
class JobberJobLocator
{
    /**
     * Resolved jobs per work order id, so repeated lookups in one request
     * (every note on a work order asks) cost a single set of queries.
     *
     * @var array<int, Jobber|null>
     */
    private array $jobsByWorkOrder = [];

    /**
     * The work order's Jobber job, or null when nothing links the two.
     */
    public function forWorkOrder(WorkOrder $workOrder): ?Jobber
    {
        return $this->jobsByWorkOrder[$workOrder->id] ??= $this->locate($workOrder);
    }

    private function locate(WorkOrder $workOrder): ?Jobber
    {
        if (filled($workOrder->jobber_job_gid)) {
            $job = Jobber::query()->where('jobber_id', $workOrder->jobber_job_gid)->first();

            if ($job !== null) {
                return $job;
            }
        }

        if (filled($workOrder->jobber_web_uri)) {
            $job = Jobber::query()->where('jobber_web_uri', $workOrder->jobber_web_uri)->first();

            if ($job !== null) {
                return $job;
            }
        }

        // App-created titles end "- #<number>"; the suffix match cannot hit a
        // longer number ("#143967" does not end in "#43967").
        if (filled($workOrder->work_order_no)) {
            return Jobber::query()->where('title', 'LIKE', '%#'.(int) $workOrder->work_order_no)->first();
        }

        return null;
    }
}
