<?php

namespace App\Services;

use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * Handles a PropertyWare work order that arrives without its lease.
 *
 * PropertyWare's website-request intake creates the work order on an occupied
 * home WITHOUT the property's lease attached, even when the requester is the
 * lease's primary contact; the lease is only filled in the next time the work
 * order is saved (typically when a vendor is assigned). The importer reads the
 * work order within minutes of creation - before that save - so it captures
 * "no lease on file", WorkOrder::hasNoLeaseOnFile() reads the home as vacant,
 * and every automated message stays silent. The one-shot intake messages were
 * then lost for good, because they dispatch only at first import (WO#43937,
 * 2026-08-25).
 *
 * Two halves: tell staff at intake that the work order is muted, and re-run
 * the intake messages the moment the lease shows up.
 */
class WorkOrderLeaseService
{
    public const EVENT_LEASE_MISSING = 'work_order_lease_missing';

    /**
     * Intake is re-run only for work orders this recent. A lease showing up on
     * an older work order is data clean-up, not a request the tenant is still
     * waiting to hear back about.
     */
    public const MAX_AGE_DAYS = 7;

    /**
     * Flag a newly imported, lease-less work order on the notification bell
     * and in the Automated Messages ledger, so the muted intake messages are
     * visible instead of silently missing. Never throws: it runs inside the
     * import loop.
     */
    public function alertMissingOnIntake(WorkOrder $workOrder): void
    {
        try {
            if (! $this->isMutedByMissingLease($workOrder)) {
                return;
            }

            activity()
                ->performedOn($workOrder)
                ->event(self::EVENT_LEASE_MISSING)
                ->withProperties([
                    'work_order_id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'message' => 'Imported from PropertyWare without a lease on file: automated tenant and owner messages are muted until the lease is attached in PropertyWare.',
                    'source' => $workOrder->source,
                    'read' => false,
                ])
                ->log('No lease on file - Work Order #'.$workOrder->work_order_no);

            foreach (['tenant' => 'tenant_service_request_sms', 'owner' => 'owner_service_request_sms'] as $audience => $automation) {
                AutomatedMessageLogService::log(
                    AutomatedMessageLogService::CHANNEL_SMS,
                    $audience,
                    $automation,
                    null,
                    $workOrder,
                    null,
                    ['not_texted_reason' => 'no_lease_on_file'],
                );
            }
        } catch (\Throwable $exception) {
            Log::error('Missing-lease alert failed.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The ambiguous case: a PropertyWare work order with a tenant contact but
     * no lease, which is either a vacant home or a website request whose lease
     * has not been attached yet. Turnover, re-key and cleaning jobs are muted
     * on purpose and never alerted; a work order with no tenant contact has
     * nobody to text either way.
     */
    public function isMutedByMissingLease(WorkOrder $workOrder): bool
    {
        return $workOrder->hasNoLeaseOnFile()
            && $workOrder->tenant_id !== null
            && ! $workOrder->isVacant()
            && ! $workOrder->isRefreshCleaning();
    }

    /**
     * The lease just appeared on a work order that had none: clear the staff
     * alert and, while the intake messages are still owed, dispatch them now.
     * The senders keep their own gates and one-shot stamps, so this can never
     * double-text.
     *
     * @param  string  $source  where the lease was noticed: soap_import, work_order_import, vendor_assignment
     */
    public function handleArrival(WorkOrder $workOrder, string $source): void
    {
        try {
            $this->resolveAlerts($workOrder);

            if (! $this->intakeIsOwed($workOrder)) {
                return;
            }

            SendOwnerServiceRequestNotificationJob::dispatch($workOrder->id);
            SendTenantWorkOrderIntakeEmailJob::dispatch($workOrder->id);
            SendTenantServiceRequestNotificationJob::dispatch($workOrder->id);

            Log::info('Lease arrived on a muted work order; intake messages re-dispatched.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'lease_id' => $workOrder->lease_id,
                'source' => $source,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Lease arrival handling failed.', [
                'work_order_id' => $workOrder->id,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Whether the intake messages still need to go out: an open, recent work
     * order that now carries a lease, is not muted for another reason, and has
     * at least one intake stamp still clear.
     */
    public function intakeIsOwed(WorkOrder $workOrder): bool
    {
        if ($workOrder->lease_id === null || $workOrder->status !== 'Open') {
            return false;
        }

        if ($workOrder->skipsAutomatedMessages()) {
            return false;
        }

        if ($workOrder->tenant_service_request_notified_at !== null
            && $workOrder->owner_service_request_notified_at !== null) {
            return false;
        }

        $createdAt = $workOrder->created_date ?? $workOrder->created_at;

        return $createdAt !== null
            && Carbon::parse($createdAt)->greaterThanOrEqualTo(now()->subDays(self::MAX_AGE_DAYS));
    }

    /**
     * Mark this work order's missing-lease alerts read: the condition they
     * flagged no longer holds.
     */
    private function resolveAlerts(WorkOrder $workOrder): void
    {
        Activity::query()
            ->where('event', self::EVENT_LEASE_MISSING)
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->get()
            ->each(function (Activity $alert): void {
                $properties = $alert->properties->toArray();

                if (($properties['read'] ?? false) === true) {
                    return;
                }

                $properties['read'] = true;
                $properties['resolved_at'] = now()->toDateTimeString();
                $alert->properties = $properties;
                $alert->save();
            });
    }
}
