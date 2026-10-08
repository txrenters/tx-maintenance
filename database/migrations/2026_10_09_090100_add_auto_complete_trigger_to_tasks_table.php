<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which proof, if any, lets the system tick a checklist task on its own.
 *
 * Lives on the template line (`tasks`), not the per-work-order row, so the
 * rule is picked once on the Task Templates page and every checklist built
 * from that template follows it. Null means "a person ticks this", which is
 * what every existing row gets. The values are the trigger keys in
 * App\Services\TaskAutoCompleteService.
 *
 * Deploy: ADD COLUMN only, nullable, guarded so a re-run is a no-op.
 * Rollback drops the column; nothing else references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'auto_complete_trigger')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('auto_complete_trigger', 40)->nullable()->after('next_service_status_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tasks', 'auto_complete_trigger')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('auto_complete_trigger');
        });
    }
};
