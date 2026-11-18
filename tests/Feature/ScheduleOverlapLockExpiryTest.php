<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Every overlap lock in the schedule expires within two hours.
 *
 * withoutOverlapping() with no argument holds its lock for 24 hours. When a
 * run is lost without reaching schedule:finish — a container recreated
 * mid-run, a failed fork — that lock then blocks the command for a day. The
 * email reply and Twilio inbound syncs sat behind such locks for a day after
 * the 2026-11-06 recovery. A minutes-scale expiry turns a lost run into one
 * missed cycle.
 */
class ScheduleOverlapLockExpiryTest extends TestCase
{
    private const MAX_LOCK_MINUTES = 120;

    public function test_every_overlap_lock_expires_within_two_hours(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        $overlapping = array_filter($events, fn ($event) => $event->withoutOverlapping);

        $this->assertNotEmpty($overlapping, 'The schedule should still use overlap locks');

        foreach ($overlapping as $event) {
            $this->assertLessThanOrEqual(
                self::MAX_LOCK_MINUTES,
                $event->expiresAt,
                $event->getSummaryForDisplay().' holds its overlap lock for '.$event->expiresAt.' minutes; a lost run must not block it for a day'
            );
        }
    }
}
