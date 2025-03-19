<?php

use App\Models\Tenants;
use App\Models\Vendor;
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
        Schema::create('service_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->dateTime('scheduled_date')->nullable();
            $table->enum('status',['scheduled', 'cancelled', 'completed'])->default('scheduled');
            $table->foreignIdFor(WorkOrder::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Vendor::class,'vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tenants::class,'tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_schedules');
    }
};
