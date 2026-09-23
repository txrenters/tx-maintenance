<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh-start guard for the daily owner approval reminder: stamp every work
 * order that exists at deploy time as excluded, so turning the reminder on
 * never blasts the existing backlog. Only work orders imported AFTER this
 * deploy are ever reminded about (Earl, 2026-09-23: "no backfill, we will
 * start a fresh start"). Runs exactly once via the migration runner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_orders', 'approval_nudge_excluded_at')) {
            return;
        }

        DB::table('work_orders')
            ->whereNull('approval_nudge_excluded_at')
            ->update(['approval_nudge_excluded_at' => now()]);
    }

    /**
     * Data backfill; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
