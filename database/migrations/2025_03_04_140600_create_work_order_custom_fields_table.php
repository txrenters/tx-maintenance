<?php

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
        Schema::create('work_order_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('propertyware_id')->nullable();
            $table->string('client_data')->nullable();
            $table->string('data_type')->nullable();
            $table->integer('definition_id')->nullable();
            $table->string('field_name')->nullable();
            $table->string('field_value')->nullable();
            $table->foreignIdFor(WorkOrder::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_custom_fields');
    }
};
