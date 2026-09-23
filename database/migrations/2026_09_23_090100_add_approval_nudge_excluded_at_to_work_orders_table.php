<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent "never remind" baseline for the daily owner approval reminder.
 * The fresh-start backfill that follows stamps every work order existing at
 * deploy time, so turning the reminder on never blasts the backlog: only work
 * orders that arrive after the deploy are ever reminded about.
 *
 * Guarded so a re-run on a database that already carries the column is a
 * no-op rather than a failed deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_orders') || Schema::hasColumn('work_orders', 'approval_nudge_excluded_at')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('approval_nudge_excluded_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_orders') || ! Schema::hasColumn('work_orders', 'approval_nudge_excluded_at')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('approval_nudge_excluded_at');
        });
    }
};
