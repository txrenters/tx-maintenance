<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Give service schedules a time-of-day by widening the date columns to
     * datetime. Existing rows keep their date with a 00:00 time, which the
     * calendar still renders at the default 9-5 window.
     */
    public function up(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->dateTime('scheduled_date')->nullable()->change();
            $table->dateTime('scheduled_end_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_schedules', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->change();
            $table->date('scheduled_end_date')->nullable()->change();
        });
    }
};
