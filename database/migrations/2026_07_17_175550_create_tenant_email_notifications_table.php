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
        Schema::create('tenant_email_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type')->default('job_reminder')->index();
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->string('from_email');
            $table->string('to_email');
            $table->string('graph_message_id')->unique();
            $table->string('graph_conversation_id')->nullable()->index();
            $table->string('internet_message_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['tenant_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_email_notifications');
    }
};
