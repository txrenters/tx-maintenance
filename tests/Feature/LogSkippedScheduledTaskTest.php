<?php

namespace Tests\Feature;

use App\Listeners\LogSkippedScheduledTask;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A skipped scheduled command leaves a warning in the log. Without it the
 * scheduler's silent stall of 2026-10-31 to 11-06 produced no log line at all.
 */
class LogSkippedScheduledTaskTest extends TestCase
{
    public function test_a_skipped_task_is_logged_with_its_command_name(): void
    {
        $task = app(Schedule::class)->command('import:work-orders')->withoutOverlapping(15);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Scheduled task skipped'
                    && str_contains($context['command'], 'import:work-orders')
                    && is_string($context['reason']);
            });

        Event::dispatch(new ScheduledTaskSkipped($task));
    }

    public function test_the_listener_is_wired_to_the_scheduler_event(): void
    {
        Event::fake([ScheduledTaskSkipped::class]);

        Event::assertListening(ScheduledTaskSkipped::class, LogSkippedScheduledTask::class);
    }
}
