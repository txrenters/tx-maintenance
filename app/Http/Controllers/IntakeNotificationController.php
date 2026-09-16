<?php

namespace App\Http\Controllers;

use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\WorkOrder;
use App\Services\WorkOrderLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The WOC's manual "send intake notification" button on the tenant and owner
 * conversation tabs.
 *
 * The intake messages fire once, at import. A work order that was muted then —
 * most often a PropertyWare website request imported before its lease was
 * attached (see WorkOrderLeaseService) — recovers by itself only when the lease
 * finally arrives, which for a work order that never gets a vendor may be never.
 * This lets a human send the message that was owed.
 *
 * Bypassing the mute is the point of the button, so the reason is returned
 * first and the caller confirms before sending (?confirm=1).
 */
class IntakeNotificationController extends Controller
{
    /**
     * @var list<string>
     */
    private const AUDIENCES = ['tenant', 'owner'];

    public function store(Request $request, WorkOrder $workOrder, string $audience, WorkOrderLeaseService $leaseService): JsonResponse
    {
        if (! in_array($audience, self::AUDIENCES, true)) {
            return response()->json(['error' => 'Unknown audience.'], 422);
        }

        if ($workOrder->automationPausedFor($audience)) {
            return response()->json([
                'error' => ucfirst($audience).' automation is paused on this work order. Turn it back on to send.',
            ], 422);
        }

        // An HOA violation has its own tenant messages; the generic "we have
        // received your service request" would be wrong (see the senders).
        if ($workOrder->isHoaViolation()) {
            return response()->json([
                'error' => 'This is an HOA violation. It sends its own tenant messages instead of an intake confirmation.',
            ], 422);
        }

        $blockedReason = $this->blockedReason($workOrder, $leaseService);

        // Tell the caller why automation stayed silent and let a human decide.
        if ($blockedReason !== null && ! $request->boolean('confirm')) {
            return response()->json([
                'needs_confirmation' => true,
                'reason' => $blockedReason['reason'],
                'message' => $blockedReason['message'],
            ]);
        }

        // Past the gate the senders would apply themselves, so they are told to
        // send anyway; without this the button would queue a job that silently
        // does nothing.
        $force = $blockedReason !== null;

        $this->clearStamp($workOrder, $audience);

        if ($audience === 'tenant') {
            SendTenantServiceRequestNotificationJob::dispatch($workOrder->id, $force);
            SendTenantWorkOrderIntakeEmailJob::dispatch($workOrder->id, $force);
        } else {
            SendOwnerServiceRequestNotificationJob::dispatch($workOrder->id, $force);
        }

        return response()->json([
            'queued' => true,
            'audience' => $audience,
            'forced' => $force,
        ]);
    }

    /**
     * Why the automated intake message would stay silent, or null when nothing
     * is blocking it. Only the mutable reasons are reported: a genuinely vacant
     * or refresh-cleaning work order is muted on purpose and is refused above.
     *
     * @return array{reason: string, message: string}|null
     */
    private function blockedReason(WorkOrder $workOrder, WorkOrderLeaseService $leaseService): ?array
    {
        if ($leaseService->isMutedByMissingLease($workOrder)) {
            return [
                'reason' => 'no_lease_on_file',
                'message' => 'This work order came from PropertyWare without a lease attached, so the automatic intake message was muted. Send it anyway?',
            ];
        }

        if ($workOrder->skipsAutomatedMessages()) {
            return [
                'reason' => 'skips_automated_messages',
                'message' => 'This work order is marked vacant, turnover, re-key or refresh cleaning, so intake messages are muted on purpose. Send it anyway?',
            ];
        }

        return null;
    }

    /**
     * Re-arm the one-shot claim so the sender runs again. The senders keep
     * their own gates, so this can never double-send on its own.
     */
    private function clearStamp(WorkOrder $workOrder, string $audience): void
    {
        $column = $audience === 'tenant'
            ? 'tenant_service_request_notified_at'
            : 'owner_service_request_notified_at';

        DB::table('work_orders')
            ->where('id', $workOrder->id)
            ->update([$column => null]);
    }
}
