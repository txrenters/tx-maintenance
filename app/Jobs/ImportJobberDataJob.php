<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs the full Jobber import off the web request.
 *
 * A full account walk is one GraphQL call per page of jobs, which is minutes of
 * work — far past the point where Azure cuts the HTTP request off. The button
 * dispatches this and returns immediately.
 */
class ImportJobberDataJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A single attempt. The import is idempotent (everything is updateOrCreate)
     * but a retry restarts the whole walk from the first page, so re-running a
     * timed-out import automatically would cost far more than it recovers.
     */
    public int $tries = 1;

    /**
     * Just under the worker's own --timeout=720 so the job's failure is recorded
     * by Laravel rather than the worker being killed out from under it.
     */
    public int $timeout = 700;

    /**
     * Double-clicking the button must not stack a second full import on top of
     * the one already running.
     */
    public int $uniqueFor = 3600;

    public function handle(): void
    {
        Log::info('Jobber import job started');

        $exitCode = Artisan::call('jobber:import-jobs');

        if ($exitCode !== 0) {
            Log::error('Jobber import job finished with a failing exit code', [
                'exit_code' => $exitCode,
                'output' => Artisan::output(),
            ]);

            return;
        }

        Log::info('Jobber import job finished', ['output' => Artisan::output()]);
    }
}
