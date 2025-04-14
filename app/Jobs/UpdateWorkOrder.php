<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateWorkOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $workOrderId;

    public $data;

    /**
     * Create a new job instance.
     */
    public function __construct($workOrderId, $data)
    {
        $this->data = $data;
        $this->workOrderId = $workOrderId;
    }

    /**
     * Execute the job.
     */
    public function handle(PropertyWareService $propertyware): void
    {
        DB::beginTransaction();
        try {
            $workOrder = WorkOrder::findOrFail($this->workOrderId);
            $propertyware->updateWorkOrder($workOrder);

            DB::commit();
            Log::info('Work Order Updated', ['work_order_id' => $this->workOrderId]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work Order update failed: '.$th->getMessage(), [
                'work_order_id' => $this->workOrderId,
                'exception' => $th->getTraceAsString(),
            ]);
            throw $th; // Re-throw the exception to mark the job as failed
        }

    }
}
