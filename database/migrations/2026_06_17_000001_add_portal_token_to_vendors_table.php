<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable()->after('user_id');
        });

        // Backfill a stable per-vendor dashboard token for existing vendors.
        DB::table('vendors')->whereNull('portal_token')->orderBy('id')
            ->each(function ($row) {
                DB::table('vendors')
                    ->where('id', $row->id)
                    ->update(['portal_token' => Str::random(48)]);
            });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unique('portal_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn('portal_token');
        });
    }
};
