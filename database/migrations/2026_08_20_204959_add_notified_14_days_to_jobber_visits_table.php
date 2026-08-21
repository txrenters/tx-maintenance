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
        if (Schema::hasColumn('jobber_visits', 'notified_14_days')) {
            return;
        }

        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->boolean('notified_14_days')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobber_visits', function (Blueprint $table) {
            $table->dropColumn('notified_14_days');
        });
    }
};
