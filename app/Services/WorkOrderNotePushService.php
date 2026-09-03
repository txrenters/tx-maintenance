<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gets a dashboard note into PropertyWare when its first push did not.
 *
 * Saving a note pushes it to PropertyWare once and moves on. PropertyWare
 * being unreachable, or answering an error page, at that moment used to be
 * the end of it: the note stayed on the dashboard and the coordinator retyped
 * it in PropertyWare by hand (WO #43649). This is the shared second chance
 * behind the scheduled notes:push-pending run and the "Send to PropertyWare
 * now" link on the Notes tab.
 *
 * Before any re-push the work order's notes are read back from PropertyWare
 * and reconciled, so a note that did arrive — its id unreadable in the
 * response, or on a work order the 10-minute import no longer pages — is
 * linked to its local row instead of being sent a second time.
 */
class WorkOrderNotePushService
{
    /**
     * Minutes a note must have existed before it is re-sent: one pass of the
     * 10-minute import, which links a note that did arrive.
     */
    public const GRACE_MINUTES = 15;

    /**
     * Days after which a note PropertyWare keeps refusing is left alone.
     */
    public const GIVE_UP_DAYS = 7;

    /**
     * How long to wait after the last attempt, by the note's age in minutes:
     * every 10 minutes for its first two hours, hourly for the rest of the
     * day, every six hours until it is a week old — about forty tries.
     *
     * @var array<int, array{0: int, 1: int}> [age below (minutes), wait (minutes)]
     */
    private const BACKOFF = [
        [120, 10],
        [1440, 60],
        [PHP_INT_MAX, 360],
    ];

    public function __construct(
        private PropertyWareService $propertyWare,
        private WorkOrderNoteSyncService $noteSync,
    ) {}

    /**
     * Link every local note PropertyWare already holds for the work order.
     *
     * False when PropertyWare could not be read or does not know the number;
     * nothing is changed then, and nothing should be sent either — a push
     * on top of an unreadable PropertyWare is how a note gets doubled.
     */
    public function reconcile(WorkOrder $workOrder): bool
    {
        if (blank($workOrder->work_order_no)) {
            return false;
        }

        try {
            $notes = $this->propertyWare->workOrderNotesFromPropertyWare($workOrder->work_order_no);
        } catch (Throwable $exception) {
            Log::warning('Work order notes could not be read from PropertyWare.', [
                'work_order_no' => $workOrder->work_order_no,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($notes === null) {
            return false;
        }

        DB::transaction(fn () => $this->noteSync->syncFromPropertyWare($workOrder->id, $notes));

        return true;
    }

    /**
     * One more attempt at PropertyWare. A failed attempt is stamped on the row
     * (updated_at) so the schedule's backoff can see when it last tried.
     */
    public function push(WorkOrderNotes $note): bool
    {
        try {
            $pushed = $this->propertyWare->addVendorNotes($note);
        } catch (Throwable $exception) {
            Log::error('Work order note could not be pushed to PropertyWare.', [
                'work_order_id' => $note->work_order_id,
                'note_id' => $note->id,
                'error' => $exception->getMessage(),
            ]);
            $pushed = false;
        }

        if (! $pushed) {
            $note->touchQuietly();
        }

        return $pushed;
    }

    /**
     * Whether the schedule should try this note now. updated_at is the last
     * attempt (the save itself, before any). The 10-minute run lands a few
     * seconds off from one time to the next, so a minute of slack keeps a
     * note from skipping every other run.
     */
    public function isDue(WorkOrderNotes $note, CarbonInterface $now): bool
    {
        $ageMinutes = $note->created_at->diffInMinutes($now, true);
        $waitMinutes = self::BACKOFF[count(self::BACKOFF) - 1][1];

        foreach (self::BACKOFF as [$ageBelow, $wait]) {
            if ($ageMinutes < $ageBelow) {
                $waitMinutes = $wait;
                break;
            }
        }

        $lastAttempt = $note->updated_at ?? $note->created_at;

        return $lastAttempt->diffInMinutes($now, true) >= $waitMinutes - 1;
    }
}
