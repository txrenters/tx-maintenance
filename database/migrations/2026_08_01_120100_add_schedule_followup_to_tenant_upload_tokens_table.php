<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule follow-up bookkeeping for the tenant portal token, mirroring the
 * same columns on owner_portal_tokens. They live on the token rather than on
 * work_orders so the counters sit next to the link the follow-up sends, and so
 * a work order can run the vendor-contact follow-up and the schedule follow-up
 * independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_upload_tokens', function (Blueprint $table) {
            $table->timestamp('responded_at')->nullable()->after('completed_at');
            $table->timestamp('schedule_followup_excluded_at')->nullable()->after('responded_at');
            $table->timestamp('schedule_followup_last_sent_at')->nullable()->after('schedule_followup_excluded_at');
            $table->unsignedInteger('schedule_followup_count')->default(0)->after('schedule_followup_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_upload_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'responded_at',
                'schedule_followup_excluded_at',
                'schedule_followup_last_sent_at',
                'schedule_followup_count',
            ]);
        });
    }
};
