<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->boolean('is_emergency')->nullable()->after('needs_human_review');
            $table->string('emergency_category')->nullable()->after('is_emergency');
            $table->unsignedTinyInteger('emergency_confidence')->nullable()->after('emergency_category');
            $table->string('emergency_reason')->nullable()->after('emergency_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->dropColumn(['is_emergency', 'emergency_category', 'emergency_confidence', 'emergency_reason']);
        });
    }
};
