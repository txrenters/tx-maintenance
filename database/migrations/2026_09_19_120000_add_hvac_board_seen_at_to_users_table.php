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
        if (Schema::hasColumn('users', 'hvac_board_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Powers the "new activity" counters on the HVAC board: every work
            // order whose updated_at is newer than this is shown as new to this
            // user. Nullable and per user, so two coordinators watching the same
            // board never clear each other's counts. Adding a nullable column is
            // an instant metadata change on MySQL 8 — no table rewrite, no lock.
            $table->timestamp('hvac_board_seen_at')->nullable()->after('updated_at');
        });

        // Everything that already exists predates the counters — mark it seen so
        // nobody opens the board to every card flagged as new.
        DB::table('users')->update(['hvac_board_seen_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'hvac_board_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hvac_board_seen_at');
        });
    }
};
