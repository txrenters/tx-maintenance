<?php

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
        Schema::create('work_order_notes', function (Blueprint $table) {
            $table->id();
            $table->longText('client_data')->nullable();
            $table->bigInteger('propertyware_id')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('date')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignIdFor(WorkOrder::class, 'work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_notes');
    }
};
