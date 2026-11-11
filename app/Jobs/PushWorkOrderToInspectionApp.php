<?php

namespace App\Jobs;

use App\Models\InspectionAppHandoff;
use App\Services\InspectionApp\HandoffService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carry one outbox row to the technicians' inspection app. Dispatched the
 * moment a work order qualifies (HandoffService::consider) and again by the
 * sweep for anything that did not get through. The retries cover an outage:
 * 15 s, 1 min, 5 min, 30 min, 1 h. A refusal the app will not change its
 * mind about ends the job without retrying (the row says why).
 */
class PushWorkOrderToInspectionApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 300, 1800, 3600];

    public function __construct(
        public int $handoffId,
    ) {}

    public function handle(HandoffService $service): void
    {
        $service->push($this->handoffId);
    }

    /**
     * Every retry spent: the row stays visible as failed with the last error
     * on it, and the sweep tries again once the work order changes.
     */
    public function failed(Throwable $exception): void
    {
        $row = InspectionAppHandoff::query()->find($this->handoffId);

        if ($row && $row->status === InspectionAppHandoff::STATUS_PENDING) {
            $row->forceFill([
                'status' => InspectionAppHandoff::STATUS_FAILED,
                'last_error' => 'Gave up after '.$row->attempts.' attempts: '.$exception->getMessage(),
            ])->save();
        }

        Log::error('Inspection app hand-off permanently failed.', [
            'handoff_id' => $this->handoffId,
            'work_order_id' => $row?->work_order_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
