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
        if (Schema::hasTable('invoice_email_replies')) {
            return;
        }

        // One row per email the invoices mailbox poller has looked at, replied
        // to or not. The unique Graph id is what stops a message being answered
        // twice, and the (from_email, replied_at) pair is what caps a sender to
        // one reply a day. Skips are recorded too so the poller never has to
        // re-decide the same message on the next tick.
        Schema::create('invoice_email_replies', function (Blueprint $table) {
            $table->id();
            $table->string('graph_message_id')->unique();
            $table->string('from_email');
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('outcome', 40);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['from_email', 'replied_at'], 'invoice_email_replies_sender_replied_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping this loses only the poller's own ledger; the emails themselves
     * stay in the mailbox untouched.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_email_replies');
    }
};
