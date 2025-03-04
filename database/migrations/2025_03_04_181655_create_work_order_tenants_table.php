<?php

use App\Models\Tenants;
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
        Schema::create('work_order_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(WorkOrder::class,'work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tenants::class,'tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_tenants');
    }
};
