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
            $table->string('twilio_sid', 64)->nullable()->after('error_message')->index();
            $table->string('twilio_status', 32)->nullable()->after('twilio_sid')->index();
            $table->string('twilio_error_code', 32)->nullable()->after('twilio_status');
            $table->text('twilio_error_message')->nullable()->after('twilio_error_code');
            $table->timestamp('twilio_status_updated_at')->nullable()->after('twilio_error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_text_messages', function (Blueprint $table) {
            $table->dropColumn([
                'twilio_sid',
                'twilio_status',
                'twilio_error_code',
                'twilio_error_message',
                'twilio_status_updated_at',
            ]);
        });
    }
};
