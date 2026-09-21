<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a technician lives, as one free-text line the office types.
 *
 * Deliberately not the parked scheduler branch's `home_address`: that one
 * carries geocoding and coordinates because a routing engine reads it.
 * This is a plain field on the profile, next to the phone and the email.
 *
 * Guarded both ways — a database that already carries the parked branch's
 * larger technicians table keeps whatever it has, and a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technicians') || Schema::hasColumn('technicians', 'address')) {
            return;
        }

        Schema::table('technicians', function (Blueprint $table) {
            $table->string('address', 200)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('technicians') || ! Schema::hasColumn('technicians', 'address')) {
            return;
        }

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
