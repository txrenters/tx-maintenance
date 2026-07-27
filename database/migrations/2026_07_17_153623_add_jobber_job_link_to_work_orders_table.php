<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When THMP is assigned, the matching Jobber job is created via the API and
     * its global id + deep link are stored here so the work order page can show
     * an "Open in Jobber" button. Nullable: only THMP work orders are linked.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('jobber_job_gid')->nullable()->after('local_status');
            $table->string('jobber_web_uri')->nullable()->after('jobber_job_gid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['jobber_job_gid', 'jobber_web_uri']);
        });
    }
};
