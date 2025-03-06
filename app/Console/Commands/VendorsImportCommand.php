<?php

namespace App\Console\Commands;

use App\Jobs\ImportVendorsJobs;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function PHPUnit\Framework\isEmpty;

class VendorsImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:vendors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import vendors from PropertyWare API every minute';

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
        ImportVendorsJobs::dispatch($this->propertyWareService->getVendors());
    }
}