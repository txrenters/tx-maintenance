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
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jobber_id');
            $table->string('name');
            $table->string('phone');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
            $table->foreign('jobber_id')->references('id')->on('jobber')->onDelete('cascade');
            $table->index(['jobber_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};