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
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->after('vendor_id');
        });

        // Backfill a unique token for every existing assignment so the
        // copy-link / portal access works for vendors assigned before this feature.
        DB::table('work_order_vendors')->whereNull('access_token')->orderBy('id')
            ->each(function ($row) {
                DB::table('work_order_vendors')
                    ->where('id', $row->id)
                    ->update(['access_token' => Str::random(48)]);
            });

        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->unique('access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_vendors', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};
