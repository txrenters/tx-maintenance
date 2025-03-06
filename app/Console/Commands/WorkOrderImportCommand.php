<?php

namespace App\Console\Commands;

use App\Jobs\ImportWorkOrderJob;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
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
        ImportWorkOrderJob::dispatch($this->propertyWareService->getWorkOrders());
    }
}
