<?php

use App\Models\Conversation;
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
        Schema::create('twilio_messages', function (Blueprint $table) {
            $table->id();
            $table->string('twilio_sid');
            $table->string('status')->nullable();
            $table->string('direction')->nullable();
            $table->foreignIdFor(Conversation::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('twilio_messages');
    }
};
