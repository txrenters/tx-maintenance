<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The HVAC board's "new activity" counters go from two allow-listed logins
     * to every staff login. The others still carry the seen mark the 09-19
     * migration backfilled, so without this they would open the board to every
     * change since then. Start everyone from now.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'hvac_board_seen_at')) {
            return;
        }

        DB::table('users')->update(['hvac_board_seen_at' => now()]);
    }

    /**
     * Nothing to put back: the earlier marks only produced badges nobody saw.
     */
    public function down(): void
    {
        //
    }
};
