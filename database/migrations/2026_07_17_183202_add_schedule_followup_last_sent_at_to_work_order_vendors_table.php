<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The daily vendor follow-up nudges an unscheduled assignment every day.
     * `schedule_followup_last_sent_at` throttles that to at most one text per
     * day, and is kept separate from `schedule_followup_sent_at` — which stays
     * the permanent "handled / excluded" baseline (fresh-start backlog and the
     * OWNER VENDOR placeholder) so enabling daily sends never blasts the
     * pre-existing backlog.
     */
    public function up(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->timestamp('schedule_followup_last_sent_at')->nullable()->after('schedule_followup_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->dropColumn('schedule_followup_last_sent_at');
        });
    }
};
