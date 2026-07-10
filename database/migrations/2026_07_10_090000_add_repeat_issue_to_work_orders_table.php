<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Whether the recommendation engine judged this work order a repeat of
            // prior maintenance at the same property. Nullable: null means "not yet
            // assessed" (mirrors is_emergency), false "assessed, not a repeat".
            $table->boolean('is_repeat_issue')->nullable()->after('is_emergency');
            // How many prior matching work orders were found at the same property
            // within the lookback window — powers the "Nth time" badge.
            $table->unsignedInteger('repeat_count')->nullable()->after('is_repeat_issue');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['is_repeat_issue', 'repeat_count']);
        });
    }
};
