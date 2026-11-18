<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rule let a work order through to the inspection app, why a held one
 * is waiting, and the AI reading that spoke for an owner approval found in
 * messages. See the 2026-11-19 approval-filter spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_app_handoffs', function (Blueprint $table): void {
            $table->string('rule', 40)->nullable()->index()->after('reason');
            $table->string('held_reason', 200)->nullable()->after('rule');
            $table->foreignId('ai_insight_id')->nullable()->after('held_reason')->constrained('ai_insights')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_app_handoffs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_insight_id');
            $table->dropColumn(['rule', 'held_reason']);
        });
    }
};
