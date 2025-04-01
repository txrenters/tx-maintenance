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
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'credit_limit',
                'payment_term_days_to_pay',
                'payment_terms',
                'taxID',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('account_number')->nullable();
            $table->string('credit_limit')->nullable();
            $table->string('payment_term_days_to_pay')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('taxID')->nullable();
        });
    }
};
