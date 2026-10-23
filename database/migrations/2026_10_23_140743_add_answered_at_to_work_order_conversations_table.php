<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a staff reply answered an inbound message. Set by
     * AnsweredNotificationReader; automated texts never set it.
     */
    public function up(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->dropColumn('answered_at');
        });
    }
};
