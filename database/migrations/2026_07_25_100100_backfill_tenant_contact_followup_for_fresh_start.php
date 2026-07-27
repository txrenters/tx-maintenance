<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fresh-start guard for the tenant vendor-contact follow-up: stamp every
     * work order that exists at deploy time as already excluded, so turning the
     * feature on never blasts the existing backlog. Only work orders whose
     * vendor is assigned AFTER this deploy will ever be texted. Runs exactly
     * once via the migration runner (same effect as
     * `tenants:backfill-vendor-followup`).
     */
    public function up(): void
    {
        DB::table('work_orders')
            ->whereNull('tenant_contact_followup_excluded_at')
            ->update(['tenant_contact_followup_excluded_at' => now()]);
    }

    /**
     * Data backfill; nothing meaningful to reverse.
     */
    public function down(): void
    {
        // Intentionally left empty.
    }
};
