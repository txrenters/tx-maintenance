<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos and files uploaded against a Jobber job.
 *
 * Deliberately a separate table from `attachments` rather than a nullable
 * work_order_id on it: every consumer of `attachments` (UploadAttachment, the
 * PropertyWare sync, AttachmentScope, the owner and tenant portals) assumes a
 * work order exists. A dedicated table makes a stray PropertyWare write or an
 * owner/tenant leak structurally impossible instead of merely guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobber_job_attachments')) {
            return;
        }

        Schema::create('jobber_job_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobber_job_id')->constrained('jobber_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('title');
            $table->text('filename');
            $table->string('filetype')->nullable();
            $table->string('type', 20)->default('attachment');
            $table->string('uploaded_via', 20)->default('staff');
            $table->timestamps();

            $table->index(['jobber_job_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobber_job_attachments');
    }
};
