<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks the daily "has the assigned vendor reached out to you?" follow-up
     * text to the tenant, so the command can claim each day's send atomically
     * and never text the same tenant twice for one work order.
     *
     *  - tenant_contact_followup_count       how many follow-ups have gone out
     *    (enforces the cap).
     *  - tenant_contact_followup_last_sent_at the once-per-day throttle.
     *  - tenant_contact_followup_excluded_at  permanent "handled / excluded"
     *    baseline — the fresh-start backfill stamps the pre-existing backlog so
     *    turning the feature on never blasts it.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedInteger('tenant_contact_followup_count')->default(0);
            $table->timestamp('tenant_contact_followup_last_sent_at')->nullable();
            $table->timestamp('tenant_contact_followup_excluded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'tenant_contact_followup_count',
                'tenant_contact_followup_last_sent_at',
                'tenant_contact_followup_excluded_at',
            ]);
        });
    }
};
