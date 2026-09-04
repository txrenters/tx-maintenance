<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record that the office has posted an invoice to the accounting system.
 *
 * Work order coordinators work down this list a page at a time and lose track
 * of which invoices they have already put through, so the state has to outlive
 * the session and be the same for whoever looks next. Kept apart from `status`,
 * which is the vendor-facing approve/decline and is written by a different
 * screen: posting is our own bookkeeping, and overloading `status` would make
 * an approval look like a posting.
 *
 * There is no boolean column. Posted means posted_at is set, matching
 * viewed_by_staff_at on attachments and closed_at on Jobber jobs, so the
 * timestamp cannot disagree with a flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded per column rather than once for both: these are separate
        // ALTERs, so a re-run after one of them failed has to add whichever is
        // still missing instead of skipping the rest.
        if (! Schema::hasColumn('invoices', 'posted_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestamp('posted_at')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('invoices', 'posted_by_user_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'posted_by_user_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('posted_by_user_id');
            });
        }

        if (Schema::hasColumn('invoices', 'posted_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                // Drop the index before the column it covers. MySQL removes it
                // with the column, but SQLite aborts the whole rollback
                // mid-way, which leaves the table half-migrated and the
                // migration still recorded as applied.
                $table->dropIndex(['posted_at']);
                $table->dropColumn('posted_at');
            });
        }
    }
};
