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
            $table->foreignId('work_order_id')
                ->nullable()
                ->after('jobber_job_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_email_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
        });
    }
};
