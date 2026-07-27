<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor assignments for Jobber jobs (non-TexasRenters client properties).
 *
 * Mirrors work_order_vendors, but for jobs that exist only in Jobber: they have
 * no PropertyWare record, no building, no tenant and no owner, so they can never
 * be represented as a work order. Each row carries its own magic-link
 * access_token, exactly like the work-order vendor portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobber_job_vendors')) {
            return;
        }

        Schema::create('jobber_job_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobber_job_id')->constrained('jobber_jobs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('access_token', 64)->nullable()->unique();
            $table->decimal('cost_estimate', 10, 2)->nullable();
            $table->date('scheduled_end_date')->nullable();
            $table->timestamp('information_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['jobber_job_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobber_job_vendors');
    }
};
