<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily owner-approval reminder bookkeeping, next to the schedule follow-up
 * counters on the same token: when this owner was last reminded that the
 * work order is waiting on their approval, and how many times. The reminder
 * has no cap (it copies PropertyWare's daily "Work Order Pending Approval"
 * alert), so the count is for the ledger, not a stop rule.
 *
 * Guarded so a re-run on a database that already carries the columns is a
 * no-op rather than a failed deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_portal_tokens')) {
            return;
        }

        if (! Schema::hasColumn('owner_portal_tokens', 'approval_nudge_last_sent_at')) {
            Schema::table('owner_portal_tokens', function (Blueprint $table) {
                $table->timestamp('approval_nudge_last_sent_at')->nullable()->after('schedule_followup_count');
            });
        }

        if (! Schema::hasColumn('owner_portal_tokens', 'approval_nudge_count')) {
            Schema::table('owner_portal_tokens', function (Blueprint $table) {
                $table->unsignedInteger('approval_nudge_count')->default(0)->after('approval_nudge_last_sent_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('owner_portal_tokens')) {
            return;
        }

        $columns = array_values(array_filter(
            ['approval_nudge_last_sent_at', 'approval_nudge_count'],
            fn (string $column): bool => Schema::hasColumn('owner_portal_tokens', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('owner_portal_tokens', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
