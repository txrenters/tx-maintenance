<?php

namespace App\Services;

use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\DB;
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

            $response = $client->getVendors();
        
            return $response;
            
        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function getWorkOrders()
    {
        try {

            $client = $this->iniate();
            $allWorkOrders = [];
        
            for ($pageNumber = 1; $pageNumber <= 15; $pageNumber++) { 
                $params = [
                    'pageNumber' => $pageNumber,
                    'orderByNewestFirst' => 1,
                ];
                $response = $client->getWorkOrders($params);
        
                if (!empty($response)) {
                    $orders = json_decode(json_encode($response), true);
                    $allWorkOrders = array_merge($allWorkOrders, $orders);
                }
            }
        
            return $allWorkOrders;

        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }

    }


    public function getWorkOrderByNumber($workOrder)
    {
        try {
            $client = $this->iniate(); // Ensure this initializes the SOAP client properly
            // $response = $client->getWorkOrder($workOrder->propertyware_id);

            // if (!empty($response)) {
            //     return json_decode(json_encode($response), true);
            // }

            // return 'No work order found';

            // Get all available SOAP functions
            $criteria = $client->getWorkOrderSearchCriteria();

            return json_decode(json_encode($criteria), true);

        return 'No work order found';

        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function getOwners()
    {
        try {
            $client = $this->iniate();
            $allOwners = [];
            $pageNumber = 1;
            $hasMorePages = true; // Assume there are more pages initially
        
            while ($hasMorePages) {
                // Call the API with pagination
                $params = [
                    'pageNumber' => $pageNumber,
                    'orderByNewestFirst' => 1,
                ];
                $response = $client->getOwners($params);
        
                if (!empty($response)) {
                    $owners = json_decode(json_encode($response), true);
                    $allOwners = array_merge($allOwners, $owners);
                }
        
                // Check if we received less than the expected page size (e.g., 10), meaning no more pages
                if (count($owners) < 10) {
                    $hasMorePages = false;
                } else {
                    $pageNumber++; // Increment to fetch the next page
                }
            }
        
            return $allOwners;

        } catch (Exception $e) {
            Log::error('SOAP request failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
        
    }

    public function updateWorkOrder(array $data, $work_order)
    {
        try {
            Log::info("Work Order ID:", ['propertyware_id' => $work_order->propertyware_id]);

            $xmlPayload = '
                <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.web.propertyware.realpage.com"
                xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                <soapenv:Header/>
                <soapenv:Body>
                    <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                        <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                            <ID xsi:type="xsd:long">' . (int)$work_order->propertyware_id . '</ID>
                            <building xsi:type="urn:Building">
                                <ID xsi:type="xsd:long">' . (int)$work_order->building_id . '</ID>
                            </building>
                            <portfolio xsi:type="urn:Portfolio">
                                <ID xsi:type="xsd:long">' . (int)$work_order->portfolio_id . '</ID>
                            </portfolio>
                            <location xsi:type="xsd:string">' . htmlspecialchars($work_order->location, ENT_XML1, 'UTF-8') . '</location>
                            <category xsi:type="xsd:string">' . htmlspecialchars($data['category'] ?? '', ENT_XML1, 'UTF-8') . '</category>
                            <costEstimate xsi:type="xsd:double">' . (float) ($data['cost_estimate'] ?? 0) . '</costEstimate>
                            <hourEstimate xsi:type="xsd:double">' . (float) ($data['hour_estimate'] ?? 0) . '</hourEstimate>
                            <closingComments xsi:type="xsd:string">' . htmlspecialchars($data['closing_comments'] ?? '', ENT_XML1, 'UTF-8') . '</closingComments>
                            <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[3]"
                                xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Management Plan</fieldName>
                                    <value xsi:type="xsd:string">' . htmlspecialchars($data['management_plan'] ?? '', ENT_XML1, 'UTF-8') . '</value>
                                </customFields>
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Additional work needed- Reschedule</fieldName>
                                    <value xsi:type="xsd:string">' . htmlspecialchars($data['additional_work_needed_reschedule'] ?? '', ENT_XML1, 'UTF-8') . '</value>
                                </customFields>
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Zone</fieldName>
                                    <value xsi:type="xsd:string">' . htmlspecialchars($data['zone'] ?? '', ENT_XML1, 'UTF-8') . '</value>
                                </customFields>
                            </customFields>
                        </workOrder>
                    </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';
            
            // Remove unnecessary whitespace for cleaner request
            $xmlPayload = trim(str_replace(["\n", "\r"], '', $xmlPayload));
            
            // Initialize cURL
            $curl = curl_init();            

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xmlPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            // Execute the cURL request
            $response = curl_exec($curl);

            Log::info("Updating work order successful: " . json_encode($response));
            
        } catch (Exception $e) {
            Log::error('Updating work order failed: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function closeWorkOrder(object $workOrder)
    {
        try {
            $workorderId = $workOrder->propertyware_id;
            $portfolioId = (int)$workOrder->portfolio_id;
            $buildigId = $workOrder->building_id;
            $location = $workOrder->location;

            $xmlPayload = '
                    <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                    xmlns:ser="http://service.web.propertyware.realpage.com"
                    xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                    <soapenv:Header/>
                    <soapenv:Body>
                    <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                    <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                    <ID xsi:type="xsd:long">' . $workorderId . '</ID>
                    <building xsi:type="urn:Building">
                    <ID xsi:type="xsd:long">' . $buildigId . '</ID>
                    </building>
                    <portfolio xsi:type="urn:Portfolio">
                    <ID xsi:type="xsd:long">' . $portfolioId . '</ID>
                    </portfolio>
                    <location xsi:type="xsd:string">' . $location . '</location>
                    <status xsi:type="xsd:string">Close</status>
                    <completedDate xsi:type="xsd:string">Open</completedDate>
                    <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[0]"
                        xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                        <customFields xsi:type="ns2:CustomField">
                            <fieldName xsi:type="xsd:string">Service Status</fieldName>
                            <value xsi:type="xsd:string">Close</value>
                            </customFields>
                    </customFields>
                    </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                    </soapenv:Envelope>
                ';

            // Initialize cURL
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xmlPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            // Execute the cURL request
            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                Log::error('cURL error: ' . curl_error($curl));
                return false;
            }
            // Close cURL
            curl_close($curl);

            Log::info("Work order {$workOrder->work_order_no} successfully closed.");

            return true;
        } catch (\Exception $exception) {
            return false;
            Log::error('Closing work order failed: '.$exception);
        }

    }

    public function reOpenWorkOrder(object $workOrder)
    {
        try {
            $workorderId = $workOrder->propertyware_id;
            $portfolioId = (int)$workOrder->portfolio_id;
            $buildigId = $workOrder->building_id;
            $location = $workOrder->location;

            $xmlPayload = '
                    <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                    xmlns:ser="http://service.web.propertyware.realpage.com"
                    xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                    <soapenv:Header/>
                    <soapenv:Body>
                    <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                    <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                    <ID xsi:type="xsd:long">' . $workorderId . '</ID>
                    <building xsi:type="urn:Building">
                    <ID xsi:type="xsd:long">' . $buildigId . '</ID>
                    </building>
                    <portfolio xsi:type="urn:Portfolio">
                    <ID xsi:type="xsd:long">' . $portfolioId . '</ID>
                    </portfolio>
                    <location xsi:type="xsd:string">' . $location . '</location>
                    <status xsi:type="xsd:string">Open</status>
                    <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[0]"
                        xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                        <customFields xsi:type="ns2:CustomField">
                            <fieldName xsi:type="xsd:string">Service Status</fieldName>
                            <value xsi:type="xsd:string">Open</value>
                            </customFields>
                    </customFields>
                    </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                    </soapenv:Envelope>
                ';

            // Initialize cURL
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xmlPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

           // Execute the cURL request
            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                Log::error('cURL error: ' . curl_error($curl));
                return false;
            }

            // Close cURL
            curl_close($curl);

            Log::info("Work order {$workOrder->work_order_no} successfully reopened.");

            return true;

        } catch (\Exception $exception) {
            Log::error('Re-opening work order failed: '.$exception);
            return false;
        }

    }

    public function changeServiceStatusPropertyWare($workOrder, $servicestatusData)
    {

        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int)$workOrder->portfolio_id;
        $buildigId = $workOrder->building_id;
        $location = $workOrder->location;
        $serviceStatus = $servicestatusData->name;
        $xmlPayload = '
                <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.web.propertyware.realpage.com"
                xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                <soapenv:Header/>
                <soapenv:Body>
                <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                <ID xsi:type="xsd:long">' . $workorderId . '</ID>
                <building xsi:type="urn:Building">
                <ID xsi:type="xsd:long">' . $buildigId . '</ID>
                </building>
                <portfolio xsi:type="urn:Portfolio">
                <ID xsi:type="xsd:long">' . $portfolioId . '</ID>
                </portfolio>
                <location xsi:type="xsd:string">' . $location . '</location>
                <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[0]"
                    xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                    <customFields xsi:type="ns2:CustomField">
                        <fieldName xsi:type="xsd:string">Service Status</fieldName>
                        <value xsi:type="xsd:string">' . $serviceStatus . '</value>
                        </customFields>
                </customFields>
                </workOrder>
                </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

            // Initialize cURL
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xmlPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

           // Execute the cURL request
            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                Log::error('cURL error: ' . curl_error($curl));
                return false;
            }

            // Close cURL
            curl_close($curl);

            Log::info(`Work order {$workOrder->work_order_no} service status successfully changed.`);

            return true;

    }

    public function changeWorkOrderVendors($workOrder, $vendors)
    {

        $now = now();

        $vendorIDsXml = "";

        DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->delete();

        if($vendors){
            $vendorIDsXml .= "<vendorIDs xsi:type=\"soapenc:Array\" xmlns:soapenc=\"http://schemas.xmlsoap.org/soap/encoding/\">\n";
        
            foreach($vendors as $vendor){
                $vendorData = Vendor::select('id', 'propertyware_id')
                    ->where('name', 'LIKE', "%{$vendor}%")
                    ->first();
            
                DB::table('work_order_vendors')->insert([
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendorData->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $vendorIDsXml .= "<vendorID xsi:type=\"xsd:long\">$vendorData->propertyware_id</vendorID>\n";
            }
            $vendorIDsXml .= "</vendorIDs>\n";
        }

        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int)$workOrder->portfolio_id;
        $buildigId = $workOrder->building_id;
        $location = $workOrder->location;

        $xmlPayload = '
                <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.web.propertyware.realpage.com"
                xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                <soapenv:Header/>
                    <soapenv:Body>
                    <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                    <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                    <ID xsi:type="xsd:long">' . $workorderId . '</ID>
                    <building xsi:type="urn:Building">
                    <ID xsi:type="xsd:long">' . $buildigId . '</ID>
                    </building>
                    <portfolio xsi:type="urn:Portfolio">
                    <ID xsi:type="xsd:long">' . $portfolioId . '</ID>
                    </portfolio>
                    <location xsi:type="xsd:string">' . $location . '</location>
                    ' . $vendorIDsXml . '
                    </workOrder>
                    </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                </soapenv:Envelope>';

            // Initialize cURL
            $curl = curl_init();

            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xmlPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml',
                    'SOAPAction: ""', // Empty SOAPAction header
                ],
                CURLOPT_USERPWD => $this->username . ':' . $this->password,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

           // Execute the cURL request
            $response = curl_exec($curl);

            if (curl_errno($curl)) {
                Log::error('cURL error: ' . curl_error($curl));
                return false;
            }

            // Close cURL
            curl_close($curl);

            Log::info(`Work order  {$workOrder->work_order_no} vendors successfully changed.`);

            return true;
    }
    public function iniate()
    {
            $options = array(
                'cache_wsdl' => \WSDL_CACHE_NONE, // Use global scope
                'trace' => 1,
                'login' => $this->username,
                'password' =>$this->password,
                'connection_timeout' => 240,
                'exceptions' => true, 
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

