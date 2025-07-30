<?php

use App\Models\Jobber;
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
        Schema::create('jobber_text_messages', function (Blueprint $table) {
            $table->id();
            $table->longText('messages');
            $table->string('sender_number');
            $table->string('receiver_number');
            $table->text('image')->nullable();
            $table->foreignIdFor(Jobber::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobber_text_messages');
    }
};
