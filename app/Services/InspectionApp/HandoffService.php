<?php

namespace App\Services\InspectionApp;

use App\Jobs\PushWorkOrderToInspectionApp;
use App\Models\InspectionAppHandoff;
use App\Models\WorkOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The mailroom between this app and the technicians' inspection app.
 *
 * consider() is called at every place a vendor lands on a work order. When
 * the vendor is one of ours (HandoffRule) it writes the outbox row and
 * dispatches the push in the same moment: no batch, no timer. push() is
 * what the queued job runs: build the envelope, send it, record the answer.
 * sweep() runs every few minutes as the safety net only: rows that never got
 * through, and work orders that changed since the app last saw them.
 *
 * Switched on by INSPECTION_APP_HANDOFF_ENABLED together with the app's URL
 * and key; off, none of this reads or writes anything.
 */
class HandoffService
{
    /**
     * A pending row the sweep re-queues only when its job can be presumed
     * lost: never attempted for this long after it was written, or last
     * attempted this long ago (the job's own retries span about an hour).
     */
    private const PRESUMED_LOST_MINUTES = 120;

    private const NEVER_RAN_MINUTES = 2;

    public function __construct(
        private HandoffRule $rule,
        private ApprovalGate $gate,
        private HandoffPayload $payload,
        private InspectionAppClient $client,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.inspection_app.handoff') && $this->client->enabled();
    }

    /**
     * A vendor just landed on the work order: hand it off now if it is ours.
     * Returns the outbox row, or null when nothing was done (switched off,
     * not our vendor, or a closed work order the app has never seen).
     */
    public function consider(int $workOrderId, string $reason): ?InspectionAppHandoff
    {
        if (! $this->enabled()) {
            return null;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()->with('vendors')->find($workOrderId);

        if (! $workOrder || ! $this->rule->qualifies($workOrder)) {
            return null;
        }

        $row = InspectionAppHandoff::query()->where('work_order_id', $workOrder->id)->first();

        if (! $row && ! $this->rule->isOpen($workOrder)) {
            return null;
        }

        if (! $row && $this->startedBeforeGoLive($workOrder)) {
            return null;
        }

        // A work order the app has already been told about is updated, never
        // pulled back through the filter: a later change goes as an update.
        if ($row && $row->status !== InspectionAppHandoff::STATUS_HELD && $row->rule !== null) {
            $row->forceFill(['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => $reason])->save();
            PushWorkOrderToInspectionApp::dispatch($row->id)->afterCommit();

            return $row;
        }

        $row ??= InspectionAppHandoff::query()->create([
            'work_order_id' => $workOrder->id,
            'status' => InspectionAppHandoff::STATUS_HELD,
            'reason' => $reason,
        ]);
        $row->forceFill(['reason' => $reason])->save();

        $this->release($row, $this->gate->decide($workOrder));

        return $row;
    }

    /**
     * Apply a decision to a row: queue it under its rule, or hold it with
     * the reason. The one place a row moves from held to pending.
     */
    public function release(InspectionAppHandoff $row, ApprovalDecision $decision): void
    {
        if ($decision->send) {
            $row->forceFill([
                'status' => InspectionAppHandoff::STATUS_PENDING,
                'rule' => $decision->rule,
                'held_reason' => null,
            ])->save();
            PushWorkOrderToInspectionApp::dispatch($row->id)->afterCommit();

            return;
        }

        $row->forceFill([
            'status' => InspectionAppHandoff::STATUS_HELD,
            'rule' => null,
            'held_reason' => $decision->heldReason,
        ])->save();
    }

    /**
     * Go-live without a backfill: a work order created before
     * handoff_since is never handed off for the first time.
     */
    public function startedBeforeGoLive(WorkOrder $workOrder): bool
    {
        $since = trim((string) config('services.inspection_app.handoff_since', ''));

        if ($since === '') {
            return false;
        }

        $created = $workOrder->created_date ?? $workOrder->created_at;

        if ($created === null) {
            return false;
        }

        return Carbon::parse((string) $created)->lt(Carbon::parse($since)->startOfDay());
    }

    /**
     * Send one outbox row now. A refusal the app will not change its mind
     * about (4xx) marks the row failed and returns; an outage or a 5xx
     * leaves it pending and throws, so the queue retries with backoff.
     */
    public function push(int $handoffId): void
    {
        $row = InspectionAppHandoff::query()->find($handoffId);

        if (! $row) {
            return;
        }

        if ($row->status === InspectionAppHandoff::STATUS_HELD) {
            return;
        }

        if (! $this->enabled()) {
            Log::info('Inspection app hand-off is off; the work order stays queued.', ['work_order_id' => $row->work_order_id]);

            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()
            ->with(['vendors', 'building', 'tenants', 'outsideCustomer', 'service_status'])
            ->find($row->work_order_id);

        if (! $workOrder) {
            $row->forceFill(['status' => InspectionAppHandoff::STATUS_SKIPPED, 'last_error' => 'The work order no longer exists.'])->save();

            return;
        }

        if (! $this->rule->qualifies($workOrder)) {
            $row->forceFill(['status' => InspectionAppHandoff::STATUS_SKIPPED, 'last_error' => 'None of our vendors is on the work order any more.'])->save();

            return;
        }

        $payload = $this->payload->build($workOrder);
        $row->forceFill(['attempts' => $row->attempts + 1, 'last_attempt_at' => now()])->save();
        $requestId = sprintf('txm-wo-%s-%d', $workOrder->work_order_no ?? $workOrder->id, $row->attempts);

        try {
            $answer = $this->client->sendWorkOrder($payload, $requestId);
        } catch (InspectionAppRefusedException $exception) {
            $row->forceFill([
                'status' => $exception->retryable() ? InspectionAppHandoff::STATUS_PENDING : InspectionAppHandoff::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ])->save();

            Log::warning('Inspection app hand-off refused.', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'request_id' => $exception->requestId,
                'retry' => $exception->retryable(),
            ]);

            if ($exception->retryable()) {
                throw $exception;
            }

            return;
        } catch (ConnectionException $exception) {
            $row->forceFill([
                'status' => InspectionAppHandoff::STATUS_PENDING,
                'last_error' => 'Could not reach the inspection app: '.$exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $row->forceFill([
            'status' => InspectionAppHandoff::STATUS_SENT,
            'sent_at' => now(),
            'payload_hash' => $this->payload->hash($payload),
            'external_id' => $this->externalId($answer),
            'last_error' => null,
        ])->save();
    }

    /**
     * The safety net: queue every row that never got through and every sent
     * or refused row whose work order changed since. Returns what it found.
     *
     * @return array{checked: int, queued: int, unchanged: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $lostBefore = now()->subMinutes(self::PRESUMED_LOST_MINUTES);
        $neverRanBefore = now()->subMinutes(self::NEVER_RAN_MINUTES);

        $rows = InspectionAppHandoff::query()
            ->join('work_orders', 'work_orders.id', '=', 'inspection_app_handoffs.work_order_id')
            ->where(function ($query) use ($lostBefore, $neverRanBefore) {
                $query->where(function ($pending) use ($lostBefore, $neverRanBefore) {
                    $pending->where('inspection_app_handoffs.status', InspectionAppHandoff::STATUS_PENDING)
                        ->where(function ($when) use ($lostBefore, $neverRanBefore) {
                            $when->where(fn ($never) => $never->whereNull('inspection_app_handoffs.last_attempt_at')->where('inspection_app_handoffs.created_at', '<=', $neverRanBefore))
                                ->orWhere('inspection_app_handoffs.last_attempt_at', '<=', $lostBefore);
                        });
                })->orWhere(function ($changed) {
                    $changed->whereIn('inspection_app_handoffs.status', [InspectionAppHandoff::STATUS_SENT, InspectionAppHandoff::STATUS_FAILED])
                        ->whereRaw('work_orders.updated_at > COALESCE(inspection_app_handoffs.last_attempt_at, inspection_app_handoffs.sent_at, inspection_app_handoffs.created_at)');
                });
            })
            ->select('inspection_app_handoffs.*')
            ->orderBy('inspection_app_handoffs.id')
            ->get();

        $queued = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            if ($row->status === InspectionAppHandoff::STATUS_SENT && $row->payload_hash !== null) {
                $workOrder = WorkOrder::withoutGlobalScopes()->find($row->work_order_id);

                if ($workOrder && $this->payload->hash($this->payload->build($workOrder)) === $row->payload_hash) {
                    if (! $dryRun) {
                        $row->forceFill(['last_attempt_at' => now()])->save();
                    }
                    $unchanged++;

                    continue;
                }
            }

            $queued++;

            if ($dryRun) {
                continue;
            }

            $row->forceFill(['status' => InspectionAppHandoff::STATUS_PENDING])->save();
            PushWorkOrderToInspectionApp::dispatch($row->id)->afterCommit();
        }

        $held = $this->sweepHeld($dryRun);

        return ['checked' => $rows->count(), 'queued' => $queued, 'unchanged' => $unchanged] + $held;
    }

    /**
     * Held rows: decide each again, since an approval may have been entered
     * in PropertyWare since. The AI reading joins here (readAndRelease).
     *
     * @return array{held: int, released: int, read: int}
     */
    private function sweepHeld(bool $dryRun): array
    {
        $rows = InspectionAppHandoff::query()
            ->where('status', InspectionAppHandoff::STATUS_HELD)
            ->orderBy('id')
            ->get();

        $released = 0;

        foreach ($rows as $row) {
            $workOrder = WorkOrder::withoutGlobalScopes()->with('vendors')->find($row->work_order_id);

            if (! $workOrder || ! $this->rule->qualifies($workOrder) || ! $this->rule->isOpen($workOrder)) {
                if (! $dryRun) {
                    $row->forceFill(['status' => InspectionAppHandoff::STATUS_SKIPPED, 'last_error' => 'No longer ours, or closed before it was ever sent.'])->save();
                }

                continue;
            }

            $decision = $this->gate->decide($workOrder);

            if ($decision->send) {
                $released++;

                if (! $dryRun) {
                    $this->release($row, $decision);
                }

                continue;
            }

            if (! $dryRun) {
                $row->forceFill(['held_reason' => $decision->heldReason])->save();
            }
        }

        return ['held' => $rows->count(), 'released' => $released, 'read' => 0];
    }

    /**
     * Hand one work order off right now, by number, whatever its state:
     * the manual lever. Returns the row after the attempt, or null when the
     * work order is not one of ours.
     */
    public function pushNow(int $workOrderNo): ?InspectionAppHandoff
    {
        $workOrder = WorkOrder::withoutGlobalScopes()->with('vendors')->where('work_order_no', $workOrderNo)->orderBy('id')->first();

        if (! $workOrder || ! $this->rule->qualifies($workOrder)) {
            return null;
        }

        $row = InspectionAppHandoff::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id],
            ['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => 'manual', 'rule' => InspectionAppHandoff::RULE_MANUAL],
        );
        // The office's own lever: no filter, and a held row goes too.
        $row->forceFill(['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => 'manual', 'rule' => InspectionAppHandoff::RULE_MANUAL, 'held_reason' => null])->save();

        $this->push($row->id);

        return $row->fresh();
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function externalId(array $answer): ?string
    {
        foreach ([$answer['id'] ?? null, $answer['data']['id'] ?? null, $answer['inspection']['id'] ?? null, $answer['inspectionId'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
