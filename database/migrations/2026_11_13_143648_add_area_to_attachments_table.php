<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which part of the home a turnover "after" photo shows (Kitchen,
     * Bedroom 2, Haul Away...), picked by the vendor on the portal so the
     * office can review the photos room by room. Null for every other photo.
     * Guarded so a re-run after a partial deploy converges instead of failing.
     */
    public function up(): void
    {
        if (Schema::hasColumn('attachments', 'area')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('area', 100)->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('attachments', 'area')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};
