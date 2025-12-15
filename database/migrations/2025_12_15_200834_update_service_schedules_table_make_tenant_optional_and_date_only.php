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
        Schema::table('service_schedules', function (Blueprint $table) {
            // Drop existing foreign key constraint for tenant_id
            $table->dropForeign(['tenant_id']);

            // Make tenant_id nullable
            $table->foreignId('tenant_id')->nullable()->change();

            // Re-add foreign key with nullOnDelete
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();

            // Change scheduled_date from dateTime to date
            $table->date('scheduled_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            // Drop the modified foreign key
            $table->dropForeign(['tenant_id']);

            // Restore tenant_id to NOT NULL
            $table->foreignId('tenant_id')->nullable(false)->change();

            // Re-add foreign key with cascadeOnDelete
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Restore scheduled_date to dateTime
            $table->dateTime('scheduled_date')->nullable()->change();
        });
    }
};
