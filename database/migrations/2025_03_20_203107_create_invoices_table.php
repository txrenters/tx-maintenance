<?php

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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('filename');
            $table->string('filetype');
            $table->decimal('amount');
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending','approved', 'decline'])->default('pending');
            $table->foreignIdFor(WorkOrder::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Vendor::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
