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
        Schema::create('work_order_documents', function (Blueprint $table) {
            $table->id();
            $table->string('client_data')->nullable();
            $table->bigInteger('propertyware_id')->nullable();
            $table->string('created_by_id')->nullable();
            $table->text('description')->nullable();
            $table->string('file_data')->nullable();
            $table->string('file_type')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('is_private')->default(false);
            $table->boolean('is_publish_to_owner_portal')->default(false);
            $table->boolean('is_publish_to_tenant_portal')->default(false);
            $table->string('system_id')->nullable();
            $table->foreignIdFor(WorkOrder::class, 'work_order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_documents');
    }
};
