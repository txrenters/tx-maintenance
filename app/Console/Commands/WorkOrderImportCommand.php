<?php

namespace App\Console\Commands;

use App\Models\Owner;
use App\Models\Tenants;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:work-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import work orders from PropertyWare API every minute';

    protected PropertyWareService $propertyWareService;

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $word_orders = collect($this->propertyWareService->getWorkOrders())->toArray();
        $now = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();
            $work_orders = json_decode(json_encode($word_orders), true);

            foreach($work_orders as $work_order){

                $owner = Owner::where('propertyware_id', $work_order['owner']['ID'])->first();

                $tenant = Tenants::where('propertyware_id', $work_order['requestedByContact']['ID'])->first();

                if(is_null($owner)){

                    $ownerData = [
                        'propertyware_id' => $work_order['owner']['ID'],
                        'first_name' => $work_order['owner']['firstName'],
                        'middle_name' => $work_order['owner']['email'],
                        'last_name' => $work_order['owner']['lastName'],
                        'email' => $work_order['owner']['email'],
                        'home_phone' => $work_order['owner']['homePhone'],
                        'work_phone' => $work_order['owner']['workPhone'],
                        'address' => $work_order['owner']['address'],
                        'address2' => $work_order['owner']['address2'],
                        'city' => $work_order['owner']['city'],
                        'state' => $work_order['owner']['state'],
                        'country' => $work_order['owner']['country'],
                        'zip' => $work_order['owner']['zip'],
                        'status' => $work_order['owner']['status'],
                    ];

                    $owner = Owner::create($ownerData);

                }

                
                if(is_null($tenant)){

                    $tenantData = [
                        'propertyware_id' => $work_order['requestedByContact']['ID'],
                        'first_name' => $work_order['requestedByContact']['firstName'],
                        'middle_name' => $work_order['requestedByContact']['email'],
                        'last_name' => $work_order['requestedByContact']['lastName'],
                        'email' => $work_order['requestedByContact']['email'],
                        'birth_date' => $work_order['requestedByContact']['birthDate'],
                        'home_phone' => $work_order['requestedByContact']['homePhone'],
                        'mobile_phone' => $work_order['requestedByContact']['mobilePhone'],
                        'address' => $work_order['requestedByContact']['address'],
                        'address2' => $work_order['requestedByContact']['address2'],
                        'city' => $work_order['requestedByContact']['city'],
                        'state' => $work_order['requestedByContact']['state'],
                        'country' => $work_order['requestedByContact']['country'],
                        'zip' => $work_order['requestedByContact']['zip'],
                    ];

                    $owner = Owner::create($ownerData);

                }
            }

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Vendor import failed: ' . $th->getMessage());
        }

    }
}
