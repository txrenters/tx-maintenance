<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // outbound | inbound
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('from_email')->nullable();
            $table->string('to_email')->nullable();
            $table->json('cc')->nullable();
            $table->string('correlation_tag')->nullable()->index();
            $table->string('graph_message_id')->nullable()->unique();
            $table->string('graph_conversation_id')->nullable()->index();
            $table->string('internet_message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->boolean('has_attachments')->default(false);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
