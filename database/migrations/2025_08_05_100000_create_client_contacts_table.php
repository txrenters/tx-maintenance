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
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Jobber::class,'jobber_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
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