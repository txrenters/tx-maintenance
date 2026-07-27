<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor invoices uploaded against a Jobber job.
 *
 * Separate from `invoices` for the same reason as jobber_job_attachments: the
 * existing invoice paths sync to PropertyWare and dereference
 * $invoice->work_order->work_order_no, which would fatal on a work-order-less row.
 *
 * The job FK is nullOnDelete rather than cascade so deleting a Jobber job never
 * destroys an accounting document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobber_job_invoices')) {
            return;
        }

        Schema::create('jobber_job_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jobber_job_id')->nullable()->constrained('jobber_jobs')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->decimal('amount', 10, 2)->default(0);
            $table->text('filename');
            $table->string('filetype')->nullable();
            $table->string('status', 20)->default('approved');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['jobber_job_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobber_job_invoices');
    }
};
