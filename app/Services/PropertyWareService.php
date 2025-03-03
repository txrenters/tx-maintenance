<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PropertyWareService
{
    protected $url;
    protected $username;
    protected $password;

    public function __construct()
    {
        // Load configuration from config/services.php
        $this->url = config('services.propertyware.url');
        $this->username = config('services.propertyware.username');
        $this->password = config('services.propertyware.password');

        if (empty($this->url) || empty($this->username) || empty($this->password)) {
            Log::error('PropertyWare API: Missing PropertyWare API credentials.');
            throw new RuntimeException('Missing PropertyWare API credentials.');
        }
    }

    public function getVendors()
    {
        try {
            $client = $this->iniate();
            $vendors = $client->getVendors();
            return $vendors;
        
        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function getWorkOrders()
    {
        try {
            $client = $this->iniate();
            $workOrders = $client->getWorkOrders();
            return $workOrders;
        
        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function iniate()
    {
            $options = array(
                'cache_wsdl' => 0,
                'trace' => 1,
                'login' => $this->username,
                'password' =>$this->password,
                'stream_context' => stream_context_create(array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                ))
            );

            $client = new \SoapClient($this->url, $options);

            return $client;
    }
}
