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
            $table->boolean('notified_7_days')->default(false);
            $table->boolean('notified_3_days')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->dropColumn(['notified_7_days','notified_3_days']);
        });
    }
};
