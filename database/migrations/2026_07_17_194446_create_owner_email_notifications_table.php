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
        Schema::create('owner_email_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('vendor_assignment');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('direction')->default('outbound')->index();
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->string('from_email');
            $table->string('to_email');
            $table->json('cc')->nullable();
            $table->string('correlation_tag')->nullable()->index();
            $table->string('graph_message_id')->nullable()->unique();
            $table->string('graph_conversation_id')->nullable()->index();
            $table->string('internet_message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->boolean('has_attachments')->default(false);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner_email_notifications');
    }
};
