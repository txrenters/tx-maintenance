<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PropertyWare picklist values sometimes carry stray whitespace (e.g.
     * "Turnover "). MySQL's padded comparisons hide it server-side, but the
     * frontend's exact matching does not — the editable Type dropdown rendered
     * blank for such rows. Normalize existing data once; the WorkOrder type
     * mutator keeps new writes trimmed.
     */
    public function up(): void
    {
        DB::table('work_orders')
            ->whereNotNull('type')
            ->update(['type' => DB::raw('TRIM(type)')]);
    }

    /**
     * Reverse the migrations.
     *
     * The original stray whitespace is not recoverable (and not wanted).
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
