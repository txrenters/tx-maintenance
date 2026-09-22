<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tenant easy-fix verdict recorded at intake (which handbook item or
     * tenant-owned appliance the request was judged to be, or null for
     * "assessed, not one"), so the tenant text, the owner text, the intake
     * email and the board all agree on it. Guarded so a re-run, or a database
     * that already has the columns, is a no-op instead of a failed deploy
     * migration. Nullable and never backfilled.
     */
    public function up(): void
    {
        if (Schema::hasColumn('work_orders', 'easy_fix_key')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('easy_fix_key', 64)->nullable();
            $table->timestamp('easy_fix_assessed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_orders', 'easy_fix_key')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['easy_fix_key', 'easy_fix_assessed_at']);
        });
    }
};
