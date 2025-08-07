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
        Schema::table('jobber_text_messages', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('image');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->text('error_message')->nullable()->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_text_messages', function (Blueprint $table) {
            $table->dropColumn(['status', 'sent_at', 'error_message']);
        });
    }
};
