<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * One row per conversation thread — a work order plus one party, the grouping
 * the Inbox, InboxThreadRead and BoardSummaryService share — carrying the
 * message ids the Inbox and its nav badge key on:
 *
 *  - last_id          the thread's newest message of any kind
 *  - last_inbound_id  the newest message that came in from the outside
 *  - last_human_id    the newest outgoing message a person here typed
 *
 * is_read is the direction column: false came from a tenant, owner or vendor,
 * true went out from us (see BoardSummaryService). Nothing on an outgoing row
 * says whether an automation or a coordinator wrote it; the automated-message
 * ledger (AutomatedMessageLogService, keyed by conversation_id) is the only
 * record, so last_human_id is the newest outgoing row the ledger does not
 * claim. A sender missing from the ledger therefore reads as a person's reply
 * — the same reading every outgoing text had before the ledger existed.
 *
 * MAX(id) rather than MAX(created_at): ids are monotonic, so each pick is free
 * of created_at ties.
 */
class ThreadNewestMessages
{
    /**
     * The ledger property every automated sender that writes a message row
     * fills in ($extra on AutomatedMessageLogService::log) — the id of the
     * work_order_conversations row the text was stored as.
     */
    private const LEDGER_CONVERSATION_KEY = 'conversation_id';

    /**
     * @param  array<int, int>|null  $workOrderIds  only these work orders; null means every thread
     */
    public function perThread(?array $workOrderIds = null): Builder
    {
        return DB::table('work_order_conversations as m')
            ->leftJoinSub(
                $this->automatedConversationIds(),
                'auto',
                fn ($join) => $join->on('auto.conversation_id', '=', 'm.id')
            )
            ->selectRaw('MAX(m.id) as last_id')
            ->selectRaw('MAX(CASE WHEN m.is_read = 0 THEN m.id END) as last_inbound_id')
            ->selectRaw('MAX(CASE WHEN m.is_read = 1 AND auto.conversation_id IS NULL THEN m.id END) as last_human_id')
            ->when($workOrderIds !== null, fn ($query) => $query->whereIn('m.work_order_id', $workOrderIds))
            ->groupByRaw("m.work_order_id, COALESCE(NULLIF(m.conversation_type, ''), 'unknown'), COALESCE(m.vendor_id, 0), COALESCE(m.owner_id, 0)");
    }

    /**
     * The message ids the ledger records as sent by an automation, typed as
     * integers so the join above compares number to number on every driver:
     * SQLite's json_extract already yields one, MySQL's yields text.
     */
    private function automatedConversationIds(): Builder
    {
        $property = self::LEDGER_CONVERSATION_KEY;

        $extract = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.{$property}')) AS UNSIGNED)",
            default => "json_extract(properties, '$.{$property}')",
        };

        return DB::table((new Activity)->getTable())
            ->selectRaw("{$extract} as conversation_id")
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->whereNotNull('properties->'.$property);
    }
}
