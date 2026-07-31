<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when the tenant was texted that the appointment was set, so the
 * confirmation fires at most once per schedule. Cleared on a reschedule so a
 * genuinely new date re-notifies. Mirrors owner_notified_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->timestamp('tenant_notified_at')->nullable()->after('owner_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropColumn('tenant_notified_at');
        });
    }
};
