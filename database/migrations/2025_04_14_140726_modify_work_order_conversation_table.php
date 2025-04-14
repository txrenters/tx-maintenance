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
            $table->longText('message')->change(); // Change the message column to longText
            $table->string('sender_number')->nullable()->change(); // Make sender_number nullable
            $table->string('receiver_number')->nullable()->change(); // Make receiver_number nullable
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_conversations', function (Blueprint $table) {
            $table->string('message')->change(); // Change the message column back to string
            $table->string('sender_number')->nullable(false)->change(); // Make sender_number non-nullable
            $table->string('receiver_number')->nullable(false)->change(); // Make receiver_number non-nullable
        });
    }
};
