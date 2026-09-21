<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('work_orders', 'last_change_summary')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            // A short, human phrase for the most recent change to this row —
            // "moved to Scheduled", "cost updated", "marked emergency". Written
            // by WorkOrder's saving hook, read by the HVAC board's "what moved"
            // list so it can say what happened instead of just "updated".
            //
            // Deliberately a rendered phrase rather than a field diff: nothing
            // reads it programmatically, and a string costs one assignment on a
            // save that was happening anyway. Nullable because every row that
            // predates this has no recorded change, which the board reads as
            // the old generic wording.
            $table->string('last_change_summary', 120)->nullable()->after('latest_update_comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('work_orders', 'last_change_summary')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('last_change_summary');
        });
    }
};
