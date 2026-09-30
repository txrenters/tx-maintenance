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
        if (Schema::hasColumn('users', 'easy_fix_board_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // The Tenant Easy Fix board's own "seen" mark, the same as
            // hvac_board_seen_at: every easy-fix work order whose updated_at is
            // newer counts as new to this user. Nullable, an instant metadata
            // change on MySQL 8 / MariaDB — no table rewrite.
            $table->timestamp('easy_fix_board_seen_at')->nullable()->after('updated_at');
        });

        // Everything that already exists predates the board — mark it seen so
        // nobody opens it to every card flagged as new.
        DB::table('users')->update(['easy_fix_board_seen_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'easy_fix_board_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('easy_fix_board_seen_at');
        });
    }
};
