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
        Schema::create('work_order_service_request', function (Blueprint $table) {
            $table->id();
            $table->string('service_request_building')->nullable();
            $table->string('service_request_company_name')->nullable();
            $table->string('service_request_contact_email')->nullable();
            $table->string('service_request_contact_name')->nullable();
            $table->string('service_request_contact_phone')->nullable();
            $table->string('service_request_contact_phone_type')->nullable();
            $table->string('service_request_unit')->nullable();
            $table->foreignIdFor(WorkOrder::class, 'work_order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_service_request');
    }
};
