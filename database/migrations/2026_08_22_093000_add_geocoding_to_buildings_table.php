<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds per-building coordinates filled in by the geocode:buildings
     * command. Guarded so a re-run (or a database that already has the
     * columns) is a no-op instead of a failed deploy migration.
     */
    public function up(): void
    {
        if (Schema::hasColumn('buildings', 'latitude')) {
            return;
        }

        Schema::table('buildings', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('geocoded_address')->nullable();
            $table->timestamp('geocoded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geocoded_address', 'geocoded_at']);
        });
    }
};
