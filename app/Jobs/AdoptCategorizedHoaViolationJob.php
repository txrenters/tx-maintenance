<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Starts the HOA violation workflow for a work order that arrived already
 * categorized "HOA Violation" (raised in PropertyWare, or re-categorized in the
 * app) rather than through the notice upload screen.
 */
class AdoptCategorizedHoaViolationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Never retry: the work is idempotent but a retry could still race a
     * concurrent adoption into texting the tenant twice.
     */
    public int $tries = 1;

    public function __construct(public int $workOrderId) {}

    public function handle(HoaViolationIntakeService $service): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);

        if (! $workOrder) {
            return;
        }

        try {
            $service->adoptCategorizedWorkOrder($workOrder);
        } catch (\Throwable $exception) {
            Log::error('Adopting a categorized HOA violation work order failed.', [
                'work_order_id' => $this->workOrderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
