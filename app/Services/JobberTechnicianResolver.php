<?php

namespace App\Services;

use App\Models\JobberVisit;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which Jobber technician a work order's field note belongs to.
 *
 * THMP techs write dashboard notes through the shared vendor login, so the
 * note row itself can only name "Texas Home Maintenance Pros". Jobber knows
 * who was actually sent: the job's visits carry their assigned users
 * (jobber_visits.assigned_to). This resolves the work order to its Jobber job
 * and answers with the technician(s) assigned to the visit nearest the
 * moment the note was written.
 *
 * Finding the job itself is JobberJobLocator's job. Null whenever any link is
 * missing — the caller falls back to the vendor name, exactly today's behavior.
 */
class JobberTechnicianResolver
{
    /**
     * Visits with assignees per work order id, resolved once per request.
     *
     * @var array<int, Collection<int, JobberVisit>|null>
     */
    private array $visitsByWorkOrder = [];

    public function __construct(private JobberJobLocator $jobs) {}

    public function technicianForNote(WorkOrder $workOrder, ?CarbonInterface $writtenAt): ?string
    {
        $visits = $this->visitsByWorkOrder[$workOrder->id] ??= $this->assignedVisits($workOrder);

        if ($visits === null || $visits->isEmpty()) {
            return null;
        }

        $visit = $this->nearestVisit($visits, $writtenAt ?? now());

        $names = collect($visit->assigned_to ?? [])
            ->pluck('name')
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->implode(', ');

        return $names !== '' ? $names : null;
    }

    /**
     * The work order's Jobber visits that have someone assigned, or null when
     * the work order cannot be tied to a Jobber job at all.
     *
     * @return Collection<int, JobberVisit>|null
     */
    private function assignedVisits(WorkOrder $workOrder): ?Collection
    {
        return $this->jobs->forWorkOrder($workOrder)?->visits()
            ->whereNotNull('assigned_to')
            ->get(['id', 'jobber_job_id', 'start_at', 'end_at', 'completed_at', 'assigned_to']);
    }

    /**
     * The visit closest in time to when the note was written. A visit with no
     * usable timestamp sorts last, so it is only picked when nothing better
     * exists.
     *
     * @param  Collection<int, JobberVisit>  $visits
     */
    private function nearestVisit(Collection $visits, CarbonInterface $writtenAt): JobberVisit
    {
        return $visits->sortBy(function (JobberVisit $visit) use ($writtenAt): float {
            $time = $visit->start_at ?? self::parse($visit->completed_at) ?? $visit->end_at;

            return $time === null ? INF : abs($time->diffInSeconds($writtenAt));
        })->first();
    }

    /**
     * completed_at is deliberately stored as a raw string (see Jobber model);
     * a malformed value must not fail the whole notes payload.
     */
    private static function parse(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
