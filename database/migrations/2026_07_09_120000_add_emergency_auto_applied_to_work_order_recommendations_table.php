<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->boolean('emergency_auto_applied')->default(false)->after('emergency_reason');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_recommendations', function (Blueprint $table) {
            $table->dropColumn('emergency_auto_applied');
        });
    }
};
