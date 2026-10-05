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
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->string('chatbot_thread_id')->nullable()->index();
            $table->string('chatbot_direction', 10)->nullable();
            $table->string('chatbot_sender_name')->nullable();
            $table->string('chatbot_sender_email')->nullable();
        });
        Schema::create('chatbot_threads', function (Blueprint $table) {
            $table->id();
            $table->string('local_key')->unique();
            $table->string('hub_thread_id')->unique();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('party', 10);
            $table->string('phone', 30);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });
        Schema::create('chatbot_message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('work_order_conversations')->cascadeOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('hub_message_id')->nullable()->unique();
            $table->string('status')->nullable();
            $table->string('twilio_sid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('event_at', 6)->nullable();
            $table->timestamps();
        });
        Schema::create('chatbot_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_events');
        Schema::dropIfExists('chatbot_message_deliveries');
        Schema::dropIfExists('chatbot_threads');
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropColumn(['chatbot_thread_id', 'chatbot_direction', 'chatbot_sender_name', 'chatbot_sender_email']);
        });
    }
};
