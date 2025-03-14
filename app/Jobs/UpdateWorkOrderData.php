<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateWorkOrderData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;
    public $work_order_id;

    /**
     * Create a new job instance.
     */
    public function __construct($work_order_id, array $data)
    {
        $this->work_order_id = $work_order_id;
        $this->data = $data;

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find the WorkOrder by ID
        $workOrder = WorkOrder::find($this->work_order_id);

        if ($workOrder) {
            
            $workOrder->update($this->data);

            Log::info("WorkOrder updated successfully: {$this->work_order_id}");
        } else {
            Log::error("WorkOrder not found: " . $this->work_order_id);
        }
    }
}
