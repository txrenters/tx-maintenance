<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\TenantWorkOrderEmailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTenantWorkOrderIntakeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $workOrderId) {}

    public function handle(TenantWorkOrderEmailSender $sender): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);

        if (! $workOrder) {
            return;
        }

        $sender->sendIntakeConfirmation($workOrder);
    }
}
