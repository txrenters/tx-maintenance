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
    /**
     * @return array{ok: bool, status: int, message: string}
     */
    public function handle(PropertyWareService $propertyware): array
    {
        $workOrder = WorkOrder::findOrFail($this->workOrderId);

        DB::beginTransaction();
        try {
            $result = $propertyware->updateWorkOrder($workOrder, $this->data ?? []);
            DB::commit();

            return $result;
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work Order update failed: '.$th->getMessage(), [
                'word_order_no' => $workOrder->work_order_no,
                'exception' => $th->getTraceAsString(),
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Could not reach PropertyWare: '.$th->getMessage(),
            ];
        }
    }
}
