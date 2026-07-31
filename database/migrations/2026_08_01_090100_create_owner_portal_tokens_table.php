<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic-link tokens for the no-login owner portal, one per work order per
 * owner. Per-owner (rather than per work order) so a property with several
 * owners gives each of them their own link, and so the schedule follow-up can
 * stop chasing the owner who replied without silencing the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('owner_id');
            // Stamped the first time this owner replies (portal message or an
            // inbound text); the schedule follow-up stops here.
            $table->dateTime('responded_at')->nullable();
            // Permanent "handled / excluded" baseline. The fresh-start backfill
            // stamps the pre-existing backlog so it is never followed up on.
            $table->dateTime('schedule_followup_excluded_at')->nullable();
            // Once-per-day throttle + the MAX_NOTIFICATIONS cap.
            $table->dateTime('schedule_followup_last_sent_at')->nullable();
            $table->unsignedInteger('schedule_followup_count')->default(0);
            $table->timestamps();

            $table->unique(['work_order_id', 'owner_id']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_portal_tokens');
    }
};
