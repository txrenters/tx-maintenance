<?php

namespace App\Services;

use App\Ai\TenantEasyFixCriteria;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * What the Tenant Easy Fix board shows on each card: the handbook item, why the
 * card is on the board, whether the tenant was texted and checked in on, and
 * whether they sent a photo or replied.
 *
 * Built for the whole board at once in a fixed number of queries (work orders,
 * ledger, tokens, replies), each restricted to the board's own work order ids,
 * never one per card. The ledger query reads indexed columns only (log_name,
 * event, subject) — no JSON, the lesson of the 2026-09-11 outage.
 */
class EasyFixBoardDetails
{
    /**
     * @param  array<int, int>  $workOrderIds
     * @return array<int, array{
     *     item: ?string, video_url: ?string, flagged: bool, status_set: bool,
     *     texted: bool, check_ins: int, check_ins_max: int, last_check_in_at: ?string,
     *     photo_uploaded: bool, tenant_replied: bool
     * }>
     */
    public function for(array $workOrderIds): array
    {
        if ($workOrderIds === []) {
            return [];
        }

        $easyFixStatusId = ServiceStatus::query()
            ->where('name', TenantEasyFixService::EASY_FIX_STATUS)
            ->value('id');

        $workOrders = DB::table('work_orders')
            ->whereIn('id', $workOrderIds)
            ->get(['id', 'easy_fix_key', 'service_status_id'])
            ->keyBy('id');

        $ledger = DB::table('activity_log')
            ->selectRaw('subject_id, event, COUNT(*) as sent, MAX(created_at) as last_at')
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->whereIn('event', ['tenant_easy_fix_sms', 'tenant_easy_fix_follow_up_sms'])
            ->where('subject_type', (new WorkOrder)->getMorphClass())
            ->whereIn('subject_id', $workOrderIds)
            ->groupBy('subject_id', 'event')
            ->get()
            ->groupBy('subject_id');

        $tokens = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->whereIn('work_order_id', $workOrderIds)
            ->get(['work_order_id', 'created_at', 'completed_at'])
            ->keyBy('work_order_id');

        // is_read is the direction marker on work_order_conversations: false
        // means the tenant wrote it. Counted from the easy-fix token onward,
        // the same rule the check-ins use to stop.
        $lastTenantMessage = Conversation::query()
            ->selectRaw('work_order_id, MAX(created_at) as last_at')
            ->whereIn('work_order_id', $workOrderIds)
            ->where('conversation_type', 'tenant')
            ->where('is_read', false)
            ->groupBy('work_order_id')
            ->pluck('last_at', 'work_order_id');

        $details = [];

        foreach ($workOrderIds as $id) {
            $workOrder = $workOrders->get($id);
            $item = TenantEasyFixCriteria::item($workOrder?->easy_fix_key);
            $events = collect($ledger->get($id, []))->keyBy('event');
            $checkIns = $events->get('tenant_easy_fix_follow_up_sms');
            $token = $tokens->get($id);
            $lastReply = $lastTenantMessage->get($id);

            $details[$id] = [
                'item' => $item['label'] ?? $workOrder?->easy_fix_key,
                'video_url' => $item['video_url'] ?? null,
                'flagged' => $workOrder?->easy_fix_key !== null,
                'status_set' => $easyFixStatusId !== null && (int) $workOrder?->service_status_id === (int) $easyFixStatusId,
                'texted' => $events->has('tenant_easy_fix_sms'),
                'check_ins' => (int) ($checkIns->sent ?? 0),
                'check_ins_max' => TenantEasyFixService::FOLLOW_UP_DAYS,
                'last_check_in_at' => $checkIns->last_at ?? null,
                'photo_uploaded' => $token?->completed_at !== null,
                'tenant_replied' => $token !== null && $lastReply !== null && $lastReply >= $token->created_at->toDateTimeString(),
            ];
        }

        return $details;
    }
}
