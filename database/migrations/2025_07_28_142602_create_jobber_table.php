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
        Schema::create('jobber_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('jobber_id')->unique();
            $table->string('job_number')->nullable();
            $table->string('title')->nullable();
            $table->string('job_status')->nullable();
            $table->string('job_type')->nullable();
            $table->float('total')->nullable();

            $table->boolean('will_client_be_automatically_charged')->nullable();
            $table->text('instructions')->nullable();
            $table->string('jobber_web_uri')->nullable();
            $table->timestamp('booking_confirmation_sent_at')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('created_at_jobber')->nullable();
            $table->timestamp('updated_at_jobber')->nullable();

            $table->foreignId('jobber_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jobber_property_id')->constrained()->cascadeOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobber_jobs');
    }
};
