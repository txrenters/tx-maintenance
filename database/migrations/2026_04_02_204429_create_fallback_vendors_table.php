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
        Schema::create('fallback_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('contacts');
            $table->text('notes')->nullable();
            $table->json('issue_types');
            $table->json('keywords');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fallback_vendors');
    }
};
