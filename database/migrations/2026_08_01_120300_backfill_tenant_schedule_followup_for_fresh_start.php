<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fresh-start guard for the tenant schedule follow-up: stamp every token that
 * exists at deploy time as excluded, so turning the feature on never blasts the
 * existing backlog.
 *
 * The general work_order token is issued lazily by this feature's own senders,
 * so a work order predating the deploy has no token to follow up in the first
 * place. This stamps the easy-fix and HOA tokens already in production too, so
 * they can never be picked up by the new command either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_upload_tokens')
            ->whereNull('schedule_followup_excluded_at')
            ->update(['schedule_followup_excluded_at' => now()]);
    }

    /**
     * Data backfill; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
