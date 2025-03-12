<?php

use App\Models\ServiceStatus;
use App\Models\Task;
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
        Schema::create('task_details', function (Blueprint $table) {
            $table->id();
            $table->string('task_for')->nullable();
            $table->boolean('is_task_service_status_emergency')->default(false);
            $table->foreignIdFor(ServiceStatus::class, 'task_service_status_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Task::class, 'task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_details');
    }
};
