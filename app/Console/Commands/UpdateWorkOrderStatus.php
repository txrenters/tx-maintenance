<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateWorkOrderStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:work-orders-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve work orders from PropertyWare API and update status';

    protected PropertyWareService $propertyWareService;

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $work_orders = $this->propertyWareService->getWorkOrdersViaRestAPI() ?? [];

        if (empty($work_orders)) {
            Log::warning('No work orders returned from Propertyware API.');
            return;
        }
       
        Log::info('Work Orders updates are running.');

        foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
            foreach ($workOrderChunk as $order) {
                $data = (array) $order;

                dd($data);

                $ID = $data['id'] ?? null;

                if ($ID) {

                    WorkOrder::update([
                        'status' => $data['status']
                    ])
                    ->where('propertyware_id', $data['id']);

                }

            }
        }

        Log::info('Work order updated successfully!');
    }
}
