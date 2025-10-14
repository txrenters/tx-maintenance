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
        
        try {
            foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
                foreach ($workOrderChunk as $order) {
                    $data = (array) $order;

                    $ID = $data['id'] ?? null;

                    if ($ID) {

                        $workOrder = WorkOrder::where('propertyware_id', $data['id'])->first();

                        if($workOrder){
                            $workOrder->update(['status' => $data['status']]);
                            $customFieldData = [];
                            
                            foreach ($data['customFields'] as $customField) {
                                if ($customField['fieldName'] == 'Service Status') {

                                    $service_status_id = DB::table('service_status')
                                        ->whereLike('name', '%'.($customField['value'] ?? '').'%')
                                        ->value('id');

                                    $work_order_data['service_status_id'] = $service_status_id ?? 1;

                                } elseif ($customField['fieldName'] == 'Zone') {
                                    $work_order_data['zone'] = $customField['value'] ?? '';
                                } elseif ($customField['fieldName'] == 'Additional work needed- Reschedule') {
                                    $work_order_data['additional_work_needed_reschedule'] = $customField['value'] ?? '';
                                } elseif ($customField['fieldName'] == 'Management Plan') {
                                    $work_order_data['management_plan'] = $customField['value'] ?? '';
                                }
                            }

                            DB::table('work_order_custom_fields')->where('work_order_id', $workOrder->id)->delete();
                            DB::table('work_order_custom_fields')->insert($customFieldData);

                            Log::info('Work order: ', ['no' => $data['number'], 'status' => $data['status']]);
                        }
                    }
                }
            }

            Log::info('Work order updating status are successfully! Work Order: '. count($work_orders));
        } catch (\Throwable $th) {
            Log::error('Work order updated failed: '. $th->getMessage());
        }
    }
}
