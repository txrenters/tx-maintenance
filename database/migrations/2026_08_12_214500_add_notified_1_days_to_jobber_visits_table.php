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
        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->boolean('notified_1_days')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->dropColumn('notified_1_days');
        });
    }
};
