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
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $this->buildXmlPayload(),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_VERBOSE => true,
            ]);
            // Execute the cURL request
            $response = curl_exec($curl);

            // Check for errors
            if (curl_errno($curl)) {
                $error = curl_error($curl);
                // Handle the error
                return  'cURL Error: ' . $error;
            }

            // Close cURL
            curl_close($curl);
            $xmlString = $response;
            $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
            $vendors = $xml->xpath('//getVendorsReturn');

            return $vendors;
        
        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    // Example function to build XML payload
    private function buildXmlPayload()
    {
        // Replace this with the actual XML payload you need
        return '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:ser="http://service.web.propertyware.realpage.com">
             <soapenv:Header/>
             <soapenv:Body>
             <ser:getVendors soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"/>
             </soapenv:Body>
            </soapenv:Envelope>';
    }

    // Example function to parse the vendor XML response
    private function parseVendorsResponse($xmlString)
    {
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            Log::error('Failed to parse XML response');
            throw new \RuntimeException('Failed to parse XML response');
        }

        // Assuming you know the XML structure and need a specific node
        $vendors = $xml->xpath('//getVendorsReturn');

        if (empty($vendors)) {
            Log::error('No vendors found');
            throw new \RuntimeException('No vendors found');
        }

        return $vendors;
    }
}
