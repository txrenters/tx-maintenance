<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Support\Facades\Log;

/**
 * Leaves a trace when the scheduler decides not to run a due command.
 *
 * schedule:run dispatches this event and prints nothing when a command's
 * overlap lock is held, the schedule is paused, or the app is in maintenance
 * mode. Between 2026-10-31 and 11-06 every scheduled run on the VPS was lost
 * that way — the scheduler kept taking locks whose background forks failed —
 * and the log carried no line for six days. A warning per skip means the
 * next silent stall shows in the log viewer within a minute.
 */
class LogSkippedScheduledTask
{
    public function handle(ScheduledTaskSkipped $event): void
    {
        $task = $event->task;

        Log::warning('Scheduled task skipped', [
            'command' => $task->getSummaryForDisplay(),
            'reason' => $task->withoutOverlapping && $task->mutex?->exists($task)
                ? 'overlap lock held (expires '.$task->expiresAt.' min after it was taken)'
                : 'filter, maintenance mode or paused schedule',
        ]);
    }
}
