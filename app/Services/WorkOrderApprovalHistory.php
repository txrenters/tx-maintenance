<?php

namespace App\Services;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * History of a work order's approval section (flag, approver, date, comment),
 * written to the spatie activity_log table under log_name "work_order_approval"
 * so a value that is later blanked or overwritten can be read back and
 * restored.
 *
 * The approval note is entered only in PropertyWare and every sync re-writes
 * it, and PropertyWare has wiped it before (WO#44014) — the importers refuse
 * to store a blank, but any other overwrite would otherwise leave no trace.
 * Hooked from WorkOrder::booted() rather than each writer: the four columns
 * are written by the SOAP import, the REST status sync, the owner portal and
 * the PropertyWare push alike, and one missed writer is exactly the gap this
 * closes.
 *
 * Row encoding:
 *  - log_name    work_order_approval (a named log: the bell and the desktop
 *                notifier ignore it)
 *  - event       approval_recorded (first values seen) or approval_changed
 *  - subject     the WorkOrder
 *  - causer      the logged-in user, when there is one
 *  - properties  old / new values of the columns that changed, and via (the
 *                route or artisan command that made the write)
 */
class WorkOrderApprovalHistory
{
    public const LOG_NAME = 'work_order_approval';

    public const COLUMNS = ['is_approved', 'approved_by', 'approved_date', 'approval_comments'];

    /**
     * Log a created work order's approval values, when it arrived with any.
     * Never throws: this runs inside the save of every import.
     */
    public static function recordCreated(WorkOrder $workOrder): void
    {
        $new = [];

        foreach (self::COLUMNS as $column) {
            $value = self::normalise($column, $workOrder->getAttribute($column));

            if ($column === 'is_approved' ? $value === true : $value !== null) {
                $new[$column] = $value;
            }
        }

        if ($new !== []) {
            self::write($workOrder, 'approval_recorded', [], $new);
        }
    }

    /**
     * Log the approval columns an update really changed, old and new side by
     * side. A false-vs-0 or whitespace-only difference is not a change.
     */
    public static function recordUpdated(WorkOrder $workOrder): void
    {
        $old = [];
        $new = [];

        foreach (self::COLUMNS as $column) {
            if (! $workOrder->wasChanged($column)) {
                continue;
            }

            $before = self::normalise($column, $workOrder->getRawOriginal($column));
            $after = self::normalise($column, $workOrder->getAttribute($column));

            if ($before !== $after) {
                $old[$column] = $before;
                $new[$column] = $after;
            }
        }

        if ($new !== []) {
            self::write($workOrder, 'approval_changed', $old, $new);
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function write(WorkOrder $workOrder, string $event, array $old, array $new): void
    {
        try {
            activity()
                ->inLog(self::LOG_NAME)
                ->performedOn($workOrder)
                ->event($event)
                ->withProperties([
                    'work_order_no' => $workOrder->work_order_no,
                    'old' => $old,
                    'new' => $new,
                    'via' => self::via(),
                ])
                ->log($event === 'approval_recorded' ? 'Approval first recorded' : 'Approval changed');
        } catch (\Throwable $e) {
            Log::warning('Could not record the approval history entry.', [
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The newest-first history rows for a work order.
     *
     * @return Collection<int, Activity>
     */
    public static function for(WorkOrder $workOrder): Collection
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->getKey())
            ->latest('id')
            ->get();
    }

    private static function normalise(string $column, mixed $value): mixed
    {
        if ($column === 'is_approved') {
            return $value === null ? null : (bool) $value;
        }

        if ($value === null) {
            return null;
        }

        if ($column === 'approved_date') {
            return $value === '' ? null : Carbon::parse($value)->toDateString();
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * What made the write: the artisan command (scheduled syncs, and the queue
     * worker for jobs) or the named route of a web request.
     */
    private static function via(): ?string
    {
        if (App::runningInConsole()) {
            return implode(' ', array_slice($_SERVER['argv'] ?? [], 1)) ?: 'console';
        }

        $route = request()->route();

        return $route?->getName() ?? $route?->uri();
    }
}
