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
        Schema::create('jobber_visits', function (Blueprint $table) {
            $table->id();
            $table->string('jobber_id')->unique();
            $table->foreignId('jobber_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jobber_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jobber_property_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_last_scheduled_visit')->default(false);
            $table->string('visit_status')->nullable();
            $table->integer('duration')->nullable(); // in minutes
            $table->text('instructions')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
