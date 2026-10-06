<?php

namespace App\Services;

use App\Models\Jobber;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Jobber job(s) behind a work order.
 *
 * There is no foreign key between the two worlds, so jobs are found by, in
 * order: the stored Jobber GID, the stored Jobber web URL, the "#<work order
 * number>" suffix the app writes into every job title it creates, and the
 * same suffix on a job's visit titles. The last two find jobs made before
 * this app owned job creation — and jobs the office still makes by hand.
 *
 * The visit-title rule exists because of #44321 (2026-10-05): the office
 * created a job in Jobber with a blank title, typed the work order number
 * into its visit and scheduled the crew on it, 29 minutes before the app
 * created and linked its own job. The crew's notes landed on the hand-made
 * job; the linked one was never scheduled and held nothing.
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
     * @var array<int, Collection<int, Jobber>>
     */
    private array $jobsByWorkOrder = [];

    /**
     * The work order's Jobber job, or null when nothing links the two. When
     * more than one job is about the work order, the linked one wins.
     */
    public function forWorkOrder(WorkOrder $workOrder): ?Jobber
    {
        return $this->allForWorkOrder($workOrder)->first();
    }

    /**
     * Every Jobber job about the work order, the linked one first, each
     * listed once. Empty when nothing links the two.
     *
     * @return Collection<int, Jobber>
     */
    public function allForWorkOrder(WorkOrder $workOrder): Collection
    {
        return $this->jobsByWorkOrder[$workOrder->id] ??= $this->locate($workOrder);
    }

    /**
     * @return Collection<int, Jobber>
     */
    private function locate(WorkOrder $workOrder): Collection
    {
        $jobs = new Collection;

        if (filled($workOrder->jobber_job_gid)) {
            $jobs = $jobs->merge(Jobber::query()->where('jobber_id', $workOrder->jobber_job_gid)->get());
        }

        if (filled($workOrder->jobber_web_uri)) {
            $jobs = $jobs->merge(Jobber::query()->where('jobber_web_uri', $workOrder->jobber_web_uri)->get());
        }

        // App-created titles end "- #<number>"; the suffix match cannot hit a
        // longer number ("#143967" does not end in "#43967"). The office types
        // the same number into visit titles when it makes a job by hand.
        if (filled($workOrder->work_order_no)) {
            $suffix = '%#'.(int) $workOrder->work_order_no;

            $jobs = $jobs->merge(
                Jobber::query()
                    ->where(fn (Builder $query) => $query
                        ->where('title', 'LIKE', $suffix)
                        ->orWhereHas('visits', fn (Builder $visits) => $visits->where('title', 'LIKE', $suffix)))
                    ->orderBy('id')
                    ->get()
            );
        }

        return $jobs->unique('id')->values();
    }
}
