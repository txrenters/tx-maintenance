<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when the property owner was notified that a vendor set the
     * appointment, so the owner is texted at most once per schedule.
     */
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->timestamp('owner_notified_at')->nullable()->after('scheduled_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dropColumn('owner_notified_at');
        });
    }
};
