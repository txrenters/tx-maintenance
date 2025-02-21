<?php

namespace App\Console\Commands;

use App\Jobs\ImportVendorsJobs;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;

class ImportVendors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vendors:import';

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
        // $vendors = $this->propertyWareService->getVendors();


        $url = config('services.propertyware.url');
        $userName = config('services.propertyware.username');
        $password = config('services.propertyware.password');

        $options = array(
            'cache_wsdl' => 0,
            'trace' => 1,
            'login' => $userName,
            'password' => $password,
            'stream_context' => stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            ))
        );

        $client = new \SoapClient($url, $options);
        $response = $client->getVendors();

        dd($response);


        // Skip the first vendor and process the rest

        // foreach ($vendors as $vendor) {
        
        //     foreach ($vendor as $vendor_values) {
        //         // Convert SimpleXMLElement to array
        //         $data = json_decode(json_encode($vendor_values), true);
    
        //         ImportVendorsJobs::dispatch($data);
                
        //     }
        // }
    }
}
