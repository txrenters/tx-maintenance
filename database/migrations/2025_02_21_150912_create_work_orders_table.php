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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('propertyware_id')->nullable();
            $table->integer('work_order_no')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->date('approved_date')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('approval_comments')->nullable();
            $table->string('authorized_to_enter')->nullable();
            $table->string('category')->nullable();
            $table->string('closing_comments')->nullable();
            $table->date('completed_date')->nullable();
            $table->decimal('cost_estimate',10,2)->nullable();
            $table->date('created_date')->nullable();
            $table->date('enter_date')->nullable();
            $table->decimal('hour_estimate',10,2)->nullable();
            $table->string('location')->nullable();
            $table->string('priority')->nullable();
            $table->string('required_materials')->nullable();
            $table->date('scheduled_end_date')->nullable();
            $table->string('source')->nullable();
            $table->string('specific_location')->nullable();
            $table->date('start_date')->nullable();
            $table->decimal('total_cost',10,2)->nullable();
            $table->decimal('total_hour_work',10,2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
