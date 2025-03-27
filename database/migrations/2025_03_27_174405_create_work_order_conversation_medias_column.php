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
        Schema::create('work_order_conversation_medias', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Conversation::class, 'message_id')->constrained()->cascadeOnDelete();
            $table->string('original_url');
            $table->string('local_path');
            $table->string('content_type');
            $table->string('file_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_conversation_medias');
    }
};
