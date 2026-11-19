<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox for work orders handed to the technicians' inspection app: one
 * row per work order, recording whether it got there, how many times it was
 * tried, what the app answered, and when. Additive and guarded, so a re-run
 * on a database that already carries the table is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inspection_app_handoffs')) {
            return;
        }

        Schema::create('inspection_app_handoffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id')->unique();
            // pending | sent | failed | skipped
            $table->string('status', 16)->default('pending')->index();
            // What put the row here: vendor_change, import, status_sync, import_all, manual.
            $table->string('reason', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // sha256 of the last payload the app accepted; a changed work order re-sends.
            $table->string('payload_hash', 64)->nullable();
            // The app's own id for the record it made, from its answer.
            $table->string('external_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_app_handoffs');
    }
};
