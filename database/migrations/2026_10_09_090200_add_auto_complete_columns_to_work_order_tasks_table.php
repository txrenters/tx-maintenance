<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a checklist task the system ticked, and why.
 *
 * `work_order_tasks` has no completed_at or completed_by, so without these a
 * system tick is indistinguishable from a person's. The timestamp also doubles
 * as the "never again" marker: when a person un-ticks an auto-completed task,
 * the timestamp stays, and the automation skips any row that carries one, so
 * a box a person opened on purpose is never re-ticked.
 *
 * Deploy: ADD COLUMN only, both nullable, guarded per column so a re-run is
 * a no-op. Rollback drops them; the ticks themselves (status) stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_tasks', 'auto_completed_at')) {
                $table->timestamp('auto_completed_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('work_order_tasks', 'auto_complete_reason')) {
                $table->string('auto_complete_reason', 255)->nullable()->after('auto_completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_order_tasks', function (Blueprint $table) {
            foreach (['auto_complete_reason', 'auto_completed_at'] as $column) {
                if (Schema::hasColumn('work_order_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
