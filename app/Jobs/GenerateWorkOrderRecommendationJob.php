<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWorkOrderRecommendationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * $allowAutoAssign is only set by intake of a brand new work order, which
     * is the sole path permitted to auto-assign a repeat vendor.
     */
    public function __construct(public int $workOrderId, public bool $allowAutoAssign = false) {}

    public function handle(WorkOrderRecommendationService $recommendationService): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);

        if (! $workOrder instanceof WorkOrder) {
            return;
        }

        $recommendationService->generate($workOrder, $this->allowAutoAssign);
    }
}
