<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\OwnerServiceRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOwnerServiceRequestNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Never retry: a failed owner notification is not worth re-running at the
     * risk of texting the owner twice about the same request.
     */
    public int $tries = 1;

    public function __construct(public int $workOrderId) {}

    public function handle(OwnerServiceRequestNotificationService $service): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);

        if (! $workOrder) {
            return;
        }

        $service->notify($workOrder);
    }
}
