<?php

use App\Models\ServiceStatus;
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
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignIdFor(ServiceStatus::class, 'current_service_status_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_current_service_status_emergency')->default(false);
            $table->foreignIdFor(ServiceStatus::class, 'next_service_status_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_next_service_status_emergency')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
