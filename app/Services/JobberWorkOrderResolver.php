<?php

namespace App\Services;

use App\Models\JobberVisit;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * Which work order a Jobber visit belongs to.
 *
 * There is no foreign key between the two worlds. The link is found by, in
 * order: the Jobber GID the app stamps onto a work order when it creates the
 * THMP job (work_orders.jobber_job_gid), and otherwise the "#<work order
 * number>" suffix written into every job title the app creates (a few older
 * titles end in the bare number instead). Tenant Benefit Package visits match
 * neither and resolve to null.
 *
 * Work orders are read through Eloquent on purpose, so the WorkOrderScope
 * applies: a viewer only ever gets a link to a work order they may open.
 */
class JobberWorkOrderResolver
{
    /**
     * App-created titles end "- #<number>"; a few legacy ones "- <number>".
     * Anchored to the end, so "#143967" never reads as "#43967".
     */
    private const TITLE_NUMBER = '/(?:#\s*(\d{3,})|-\s+(\d{5,6}))\s*$/';

    /**
     * The linked work order per visit id, or null when the visit has none.
     * Two queries for the whole set, however many visits are passed.
     *
     * @param  Collection<int, JobberVisit>  $visits  with their job loaded
     * @return array<int, array{id: int, work_order_no: int|null}|null>
     */
    public function forVisits(Collection $visits): array
    {
        $links = [];

        $gids = $visits
            ->map(fn (JobberVisit $visit): ?string => $visit->job?->jobber_id)
            ->filter(fn (?string $gid): bool => filled($gid))
            ->unique()
            ->values()
            ->all();

        // jobber_job_gid is not unique; the lowest work order id wins a tie.
        $byGid = $gids === []
            ? collect()
            : WorkOrder::query()
                ->whereIn('jobber_job_gid', $gids)
                ->orderBy('id')
                ->get(['id', 'work_order_no', 'jobber_job_gid'])
                ->groupBy(fn (WorkOrder $workOrder): string => (string) $workOrder->jobber_job_gid)
                ->map(fn (Collection $rows): WorkOrder => $rows->first());

        $numbers = [];

        foreach ($visits as $visit) {
            $gid = $visit->job?->jobber_id;

            if (filled($gid) && $byGid->has((string) $gid)) {
                $links[$visit->id] = self::shape($byGid->get((string) $gid));

                continue;
            }

            $links[$visit->id] = null;

            $number = self::numberFromTitle($visit->job?->title) ?? self::numberFromTitle($visit->title);

            if ($number !== null) {
                $numbers[$visit->id] = $number;
            }
        }

        if ($numbers !== []) {
            $byNumber = WorkOrder::query()
                ->whereIn('work_order_no', array_values(array_unique($numbers)))
                ->orderBy('id')
                ->get(['id', 'work_order_no'])
                ->groupBy(fn (WorkOrder $workOrder): string => (string) $workOrder->work_order_no)
                ->map(fn (Collection $rows): WorkOrder => $rows->first());

            foreach ($numbers as $visitId => $number) {
                $workOrder = $byNumber->get((string) $number);

                $links[$visitId] = $workOrder ? self::shape($workOrder) : null;
            }
        }

        return $links;
    }

    /**
     * The work order number a Jobber title ends with, or null (TBP titles).
     */
    public static function numberFromTitle(?string $title): ?int
    {
        if (blank($title)) {
            return null;
        }

        if (preg_match(self::TITLE_NUMBER, trim((string) $title), $matches) !== 1) {
            return null;
        }

        return (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);
    }

    /**
     * @return array{id: int, work_order_no: int|null}
     */
    private static function shape(WorkOrder $workOrder): array
    {
        return [
            'id' => (int) $workOrder->id,
            'work_order_no' => $workOrder->work_order_no === null ? null : (int) $workOrder->work_order_no,
        ];
    }
}
