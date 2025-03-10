<?php

namespace App\Jobs;

use App\Services\PropertyWareService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateWorkOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;
    public $workOrder;
    public $vendorIDsXml;

    /**
     * Create a new job instance.
     */
    public function __construct($data, $workOrder, $vendorIDsXml = '')
    {
        $this->data = $data;
        $this->workOrder = $workOrder;
        $this->vendorIDsXml = $vendorIDsXml;
    }

    /**
     * Execute the job.
     */
    public function handle(PropertyWareService $propertyware): void
    {
        $propertyware->updateWorkOrder($this->data, $this->workOrder, $this->vendorIDsXml);
    }
}
