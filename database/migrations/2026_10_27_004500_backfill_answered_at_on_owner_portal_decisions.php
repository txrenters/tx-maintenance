<?php

use App\Services\AnsweredNotificationReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time cleanup: an owner's portal decision ("Approved." / "Not
     * approved.") is acted on, not answered, so one that already has a later
     * owner message on its work order, to any owner, staff or automated,
     * is stamped answered and leaves the Inbox's Awaiting reply list.
     *
     * A database update and nothing else: it sends no text or email and
     * changes no bell entry. Guarded so a re-run, or a run before the
     * answered_at column exists, is a no-op.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('work_order_conversations', 'answered_at')) {
            return;
        }

        $stamped = app(AnsweredNotificationReader::class)->backfillAnsweredDecisions();

        Log::info('Backfilled answered_at on acted-on owner portal decisions.', ['stamped' => $stamped]);
    }

    /**
     * Not reversible: the stamps cannot be told apart from live ones.
     */
    public function down(): void {}
};
