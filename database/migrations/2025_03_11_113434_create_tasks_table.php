<?php

use App\Models\ServiceStatus;
use App\Models\TaskTemplate;
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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_mandatory')->default(false);
            $table->string('due_date')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_emergency')->default(false);
            $table->enum('type', ['Vendor', 'Woc']);
            $table->foreignIdFor(ServiceStatus::class,'next_service_status_id')->nullable()->nullOnDelete();
            $table->foreignIdFor(TaskTemplate::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
