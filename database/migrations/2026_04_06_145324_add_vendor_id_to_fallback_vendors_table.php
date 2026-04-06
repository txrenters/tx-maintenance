<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fallback_vendors', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('id')->constrained('vendors')->nullOnDelete();
        });

        // Migrate existing data: match name to vendors table
        DB::table('fallback_vendors')->get()->each(function ($fallback) {
            $vendor = DB::table('vendors')->whereRaw('LOWER(name) = ?', [strtolower($fallback->name)])->first();
            if ($vendor) {
                DB::table('fallback_vendors')->where('id', $fallback->id)->update(['vendor_id' => $vendor->id]);
            }
        });

        Schema::table('fallback_vendors', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fallback_vendors', function (Blueprint $table) {
            $table->string('name')->after('id');
        });

        // Restore name from vendor relationship
        DB::table('fallback_vendors')->get()->each(function ($fallback) {
            if ($fallback->vendor_id) {
                $vendor = DB::table('vendors')->find($fallback->vendor_id);
                if ($vendor) {
                    DB::table('fallback_vendors')->where('id', $fallback->id)->update(['name' => $vendor->name]);
                }
            }
        });

        Schema::table('fallback_vendors', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn('vendor_id');
        });
    }
};
