<?php

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
        if (Schema::hasTable('easy_fix_board_reads')) {
            return;
        }

        // Per-user dismissals on the Tenant Easy Fix board, the same shape as
        // hvac_board_reads: the work order's updated_at at the moment it was
        // dismissed, so it comes back on its own when it moves again.
        Schema::create('easy_fix_board_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_updated_at');
            $table->timestamps();

            $table->unique(['user_id', 'work_order_id'], 'easy_fix_board_reads_user_work_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping this loses only the per-row dismissals; the board-wide
     * users.easy_fix_board_seen_at mark is a separate column and survives.
     */
    public function down(): void
    {
        Schema::dropIfExists('easy_fix_board_reads');
    }
};
