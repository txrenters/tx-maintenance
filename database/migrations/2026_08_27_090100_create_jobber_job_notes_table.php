<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal office notes on a Jobber job.
 *
 * Deliberately its own table. The one free-text field on `jobber_jobs`,
 * `instructions`, belongs to Jobber: the importer and the JOB_UPDATE webhook
 * overwrite it with whatever Jobber last said, so a note written there would
 * vanish on the next sync. And not a nullable job id on `work_order_notes`:
 * every consumer of that table assumes a work order exists and mirrors the
 * note to PropertyWare, which a Jobber job has no record in.
 *
 * Azure: CREATE TABLE only, touches no existing table, guarded so a re-run is
 * a no-op. Rollback drops the table and with it any notes written meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobber_job_notes')) {
            return;
        }

        Schema::create('jobber_job_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobber_job_id')->constrained('jobber_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobber_job_notes');
    }
};
