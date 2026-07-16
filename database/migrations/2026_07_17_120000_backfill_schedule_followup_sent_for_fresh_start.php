<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fresh-start guard for the vendor schedule follow-up: stamp every vendor
     * assignment that exists at deploy time as already followed-up, so turning
     * the feature on never blasts the existing backlog. Only assignments
     * created AFTER this deploy will ever be nudged. Runs exactly once via the
     * migration runner (same effect as `vendors:backfill-followup-sent`).
     */
    public function up(): void
    {
        DB::table('work_order_vendors')
            ->whereNull('schedule_followup_sent_at')
            ->update(['schedule_followup_sent_at' => now()]);
    }

    /**
     * Data backfill; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
