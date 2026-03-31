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
        Schema::create('work_order_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(WorkOrder::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Vendor::class, 'recommended_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('status')->default('generated');
            $table->string('source')->default('heuristic');
            $table->string('model')->nullable();
            $table->string('issue_type')->nullable()->index();
            $table->string('issue_subtype')->nullable();
            $table->string('vendor_category')->nullable()->index();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->boolean('needs_human_review')->default(false);
            $table->text('summary')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('keywords')->nullable();
            $table->json('matched_work_orders')->nullable();
            $table->json('alternate_vendors')->nullable();
            $table->json('classification')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_recommendations');
    }
};
