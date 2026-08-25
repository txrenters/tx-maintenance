<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jobber's own coordinates for each property address, filled in by the
     * Jobber syncs. The Scheduler pins from these when a PropertyWare
     * building has no coordinates of its own (new-build streets the free
     * Census geocoder does not know yet) and for Jobber properties with no
     * PropertyWare building at all. Guarded so a re-run, or a database that
     * already has the columns, is a no-op instead of a failed deploy.
     */
    public function up(): void
    {
        if (Schema::hasColumn('jobber_properties', 'latitude')) {
            return;
        }

        Schema::table('jobber_properties', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('country');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('jobber_properties', 'latitude')) {
            return;
        }

        Schema::table('jobber_properties', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
