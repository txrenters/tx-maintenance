<?php

namespace App\Console\Commands;

use App\Jobs\ImportOwnersJob;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;

class OwnersImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:owners';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import owners from PropertyWare API every minute';

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
        ImportOwnersJob::dispatch($this->propertyWareService->getOwners());
    }
}
