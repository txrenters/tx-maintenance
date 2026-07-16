<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks when the "no service schedule after 3 days" follow-up text was sent
     * for an assignment, so the daily command can claim it atomically and never
     * text the same vendor twice for the same assignment.
     */
    public function up(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->timestamp('schedule_followup_sent_at')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->dropColumn('schedule_followup_sent_at');
        });
    }
};
