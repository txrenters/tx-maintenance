<?php

use App\Models\User;
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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('name_on_check')->nullable();
            $table->string('account_number')->nullable();
            $table->string('credit_limit')->nullable();
            $table->string('payment_term_days_to_pay')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('taxID')->nullable();
            $table->string('vendor_type')->nullable();
            $table->string('twilio_number')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
