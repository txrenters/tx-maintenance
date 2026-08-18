<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for every read-only AI annotation on a work order: inbound
     * message intents, extracted schedule suggestions, completion-photo
     * reviews. `subject` is the row the verdict is about (a Conversation
     * message, an Attachments row); `data` carries the type-specific payload.
     * Kept generic on purpose so the next read-only agent needs no migration.
     */
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->nullableMorphs('subject');
            $table->string('status', 20)->default('open');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'type']);
            // One verdict per subject per type; re-runs update in place.
            $table->unique(['type', 'subject_type', 'subject_id'], 'ai_insights_type_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
