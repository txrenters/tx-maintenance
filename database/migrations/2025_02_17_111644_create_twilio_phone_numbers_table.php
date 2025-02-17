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
        Schema::create('twilio_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('account_sid');
            $table->string('sid');
            $table->string('phone_number');
            $table->string('sms_application_sid')->nullable();
            $table->text('capabilities')->nullable();
            $table->string('twilio_status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('twilio_phone_numbers');
    }
};
