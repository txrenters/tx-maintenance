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
        Schema::create('work_order_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('message');
            $table->enum('conversation_type', ['tenant', 'owner', 'vendor_tenant'])->nullable(); // 'tenant', 'vendor_tenant', 'owners', etc.
            $table->string('sender_number');
            $table->string('receiver_number');
            $table->foreignIdFor(WorkOrder::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_categories');
    }
};
