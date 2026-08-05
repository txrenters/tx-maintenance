<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-staff-user "I have seen this thread up to here" markers for the
     * Inbox. One row per (user, thread); the thread key columns mirror the
     * Inbox grouping (work order + conversation_type + vendor + owner, with
     * NULLs normalized the way InboxController::latestPerThread() does).
     *
     * work_order_conversations.is_read cannot carry this: it is the de facto
     * direction column (false = inbound) and several features depend on it
     * never being flipped after insert.
     */
    public function up(): void
    {
        Schema::create('inbox_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_type', 50)->default('unknown');
            $table->unsignedBigInteger('vendor_id')->default(0);
            $table->unsignedBigInteger('owner_id')->default(0);
            $table->unsignedBigInteger('last_read_conversation_id')->default(0);
            $table->timestamps();

            $table->unique(
                ['user_id', 'work_order_id', 'conversation_type', 'vendor_id', 'owner_id'],
                'inbox_thread_reads_thread_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_thread_reads');
    }
};
