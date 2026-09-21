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
        if (Schema::hasTable('hvac_board_reads')) {
            return;
        }

        // Per-user "I have dealt with this one" markers for the HVAC board, the
        // same shape as inbox_thread_reads. One row per (user, work order);
        // dismissing a row stores the moment it was clicked, so a work order
        // that moves again afterwards comes back on its own rather than being
        // silenced for good by one click.
        //
        // Deliberately not a boolean: a flag would have to be reset by whatever
        // touches the work order next, and nothing in the sync path knows about
        // this feature. A timestamp compared against updated_at needs no writer
        // anywhere else.
        Schema::create('hvac_board_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            // The work order's own updated_at at the moment it was dismissed,
            // NOT the wall clock. Both columns are second-precision, so a click
            // in the same second as the change would otherwise be
            // indistinguishable from one after it; comparing like against like
            // and requiring strictly-newer movement keeps that honest.
            $table->timestamp('dismissed_updated_at');
            $table->timestamps();

            $table->unique(['user_id', 'work_order_id'], 'hvac_board_reads_user_work_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping this loses only the per-row dismissals; the board-wide
     * users.hvac_board_seen_at mark is a separate column and survives.
     */
    public function down(): void
    {
        Schema::dropIfExists('hvac_board_reads');
    }
};
