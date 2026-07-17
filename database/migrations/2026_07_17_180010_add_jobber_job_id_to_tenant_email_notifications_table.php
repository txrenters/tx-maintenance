<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_email_notifications', function (Blueprint $table) {
            $table->foreignId('jobber_job_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('jobber_jobs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_email_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jobber_job_id');
        });
    }
};
