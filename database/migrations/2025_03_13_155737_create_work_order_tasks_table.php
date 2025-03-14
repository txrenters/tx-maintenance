<?php

use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
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
        Schema::create('work_order_tasks', function (Blueprint $table) {
            $table->id();
            $table->text('remarks')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('option',['Yes','No'])->nullable();
            $table->enum('status',['pending','processing','completed'])->default('pending');
            $table->foreignIdFor(WorkOrder::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class,'assigned_user_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Task::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_tasks');
    }
};
