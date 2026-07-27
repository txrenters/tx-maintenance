<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillScheduleFollowupSent extends Command
{
    protected $signature = 'vendors:backfill-followup-sent {--dry-run : Report how many assignments would be marked, without changing anything}';

    protected $description = 'Mark every existing vendor assignment as already followed-up, so enabling the daily follow-up only nudges assignments created afterward.';

    /**
     * Stamp `schedule_followup_sent_at` on all current assignments that have no
     * stamp yet. Run this once, immediately before turning the follow-up on, so
     * the existing backlog is treated as handled and only new assignments are
     * ever nudged. Touches only un-stamped rows, so it is safe to re-run.
     */
    public function handle(): int
    {
        $pending = DB::table('work_order_vendors')->whereNull('schedule_followup_sent_at');

        $count = $pending->count();

        if ($count === 0) {
            $this->info('No un-marked assignments found. Nothing to backfill.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} existing assignment(s) would be marked as followed-up. No changes made.");

            return self::SUCCESS;
        }

        $updated = DB::table('work_order_vendors')
            ->whereNull('schedule_followup_sent_at')
            ->update(['schedule_followup_sent_at' => now()]);

        $this->info("Backfilled {$updated} existing assignment(s) as followed-up. The daily follow-up will now only nudge assignments created after this point.");

        return self::SUCCESS;
    }
}
