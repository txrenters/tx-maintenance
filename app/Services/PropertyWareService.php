<?php

namespace App\Services;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

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
            Log::error('PropertyWare API: Missing credentials. Skipping API connection.');

            return; // Avoid crashing during deployment
        }
    }

    public function getWorkOrders()
    {
        try {

            $client = $this->initiate();
            $allWorkOrders = [];

            for ($pageNumber = 1; $pageNumber <= 25; $pageNumber++) {
                $params = [
                    'pageNumber' => $pageNumber,
                    'orderByNewestFirst' => 1,
                ];
                $response = $client->getWorkOrders($params);

                if (! empty($response)) {
                    $orders = json_decode(json_encode($response), true);
                    $allWorkOrders = array_merge($allWorkOrders, $orders);
                }
            }

            return $allWorkOrders;

        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function getOwners()
    {
        try {
            $client = $this->initiate();
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

                if (! empty($response)) {
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
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function getVendors()
    {
        set_time_limit(300); // <-- Add this line
        ini_set('memory_limit', '1024M'); // <-- Add this line
        try {
            $client = $this->initiate();

            $response = $client->getVendors();
            $allVendors = [];

            if (! empty($response)) {
                $vendor = json_decode(json_encode($response), true);
                $allVendors = array_merge($allVendors, $vendor);
            }

            return $allVendors;

        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }
    }

    public function getVendorsByName($vendorName)
    {
        try {
            $client = $this->initiate();

            $response = $client->getVendorByName($vendorName);

            $allVendors = [];

            if (! empty($response)) {
                $allVendors = json_decode(json_encode($response), true);
            }

            return $allVendors;

        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function getWorkOrderByNumber($workorderNo)
    {
        try {
            $client = $this->initiate();
            $params = [
                'orderByNewestFirst' => 1,
                'workOrderNumber' => $workorderNo,
                'pageNumber' => 1,
            ];

            $response = $client->getWorkOrders($params);

            $allWorkOrders = [];

            if (! empty($response)) {
                $allWorkOrders = json_decode(json_encode($response), true);
            }

            return $allWorkOrders;

        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function updateWorkOrder($workOrder)
    {
        try {
            Log::info('Work Order ID:', ['work_order_no' => $workOrder->work_order_no]);

            $approvedDataXml = '';
            if ($workOrder->is_approved) {
                $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
                $approvalComment = htmlspecialchars($workOrder->approval_comments ?? null);
                $approvedBy = htmlspecialchars($workOrder->approved_by ?? null);
            
                $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
                $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
                $approvedDataXml .= '<approvalComment>' . $approvalComment . '</approvalComment>';
            }

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
                            <ID xsi:type="xsd:long">'.(int) $workOrder->propertyware_id.'</ID>
                            <building xsi:type="urn:Building">
                                <ID xsi:type="xsd:long">'.(int) $workOrder->building_id.'</ID>
                            </building>
                            <portfolio xsi:type="urn:Portfolio">
                                <ID xsi:type="xsd:long">'.(int) $workOrder->portfolio_id.'</ID>
                            </portfolio>
                            <location xsi:type="xsd:string">'.$workOrder->location.'</location>
                            <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                            <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                            <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                            '.$approvedDataXml.'
                            <closingComments xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</closingComments>
                            <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[4]"
                                xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Management Plan</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->management_plan ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                 <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Additional work needed- Reschedule</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->additional_work_needed_reschedule ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                 <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Zone</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->zone ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                 <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">closing comment</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                            </customFields>
                        </workOrder>
                    </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

            // Execute SOAP request
            $res = $this->execute($xmlPayload);

            // Log the request payload and response for debugging
            Log::debug('SOAP Request Payload:', ['payload' => $xmlPayload]);
            Log::debug('SOAP Response:', ['response' => $res]);

            // Handle response success and failure
            if ($res && isset($res['success']) && $res['success'] === true) {
                Log::info('Work order update successful!', ['Work order no' => $workOrder->work_order_no]);

                return true;
            }

            // Log failure if response is unsuccessful
            Log::error('Work order update failed!', [
                'Work order no' => $workOrder->work_order_no,
                'response' => $res,
            ]);

            return false;

        } catch (Exception $e) {
            Log::error('Work order update failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }
    }

    public function updateServiceStatus(object $workOrder, object $service_status)
    {
        try {
            $workorderId = $workOrder->propertyware_id;
            $portfolioId = (int) $workOrder->portfolio_id;
            $buildingId = $workOrder->building_id;
            $location = $workOrder->location;

            $approvedDataXml = '';
            if ($workOrder->is_approved) {
                $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
                $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
                $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
            
                $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
                $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
                $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
            }

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
                        <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                        <building xsi:type="urn:Building">
                            <ID xsi:type="xsd:long">'.$buildingId.'</ID>
                        </building>
                        <portfolio xsi:type="urn:Portfolio">
                            <ID xsi:type="xsd:long">'.$portfolioId.'</ID>
                        </portfolio>
                        <location xsi:type="xsd:string">'.$location.'</location>

                         <location xsi:type="xsd:string">'.$workOrder->location.'</location>
                            <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                            <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                            <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                            '.$approvedDataXml.'
                            <closingComments xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</closingComments>
                            <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[3]"
                                xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Management Plan</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->management_plan ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Additional work needed- Reschedule</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->additional_work_needed_reschedule ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                <customFields xsi:type="urn:CustomField">
                                    <fieldName xsi:type="xsd:string">Zone</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->zone ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                                 <customFields xsi:type="ns2:CustomField">
                                    <fieldName xsi:type="xsd:string">Service Status</fieldName>
                                    <value xsi:type="xsd:string">'.$service_status->name.'</value>
                                </customFields>
                                <customFields xsi:type="ns2:CustomField">
                                    <fieldName xsi:type="xsd:string">closing comment</fieldName>
                                    <value xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</value>
                                </customFields>
                            </customFields>
                        </workOrder>
                        </ser:updateWorkOrder>
                        </soapenv:Body>
                    </soapenv:Envelope>
                ';

            $response = $this->execute($xmlPayload);

            // Log and return response status
            if ($response) {
                Log::info('Updating service status has been successfully!', [
                    'Work order no' => $workOrder->work_order_no,
                ]);
            }

            return true;

        } catch (\Exception $exception) {
            return false;
            Log::error('Updating service status failed: '.$exception);
        }

    }

    public function closeWorkOrder(object $workOrder, $url)
    {
        try {
            $this->buildCloseWorkOrderPayload($workOrder);

            $this->buildAttachDocumentPayload($workOrder, $url);

            return true;

        } catch (\Exception $exception) {
            Log::error('Closing work order failed: '.$exception);

            return false;
        }

    }

    private function buildAttachDocumentPayload($workOrder, $url)
    {
        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int) $workOrder->portfolio_id;
        $buildingId = $workOrder->building_id;
        $location = $workOrder->location;

        $xmlPayload2 = '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:ser="http://service.web.propertyware.realpage.com"
            xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
            <soapenv:Header/>
            <soapenv:Body>
                <ser:attachDocumentToWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                <document xsi:type="urn:Document" xmlns:urn="urn:PWServices">
                    <ID xsi:type="xsd:long">0</ID>
                    <description xsi:type="xsd:string">Please go to this link to view conversation: '.$url.'</description>
                    <fileData xsi:type="xsd:string">'.$url.'</fileData>
                    <fileType xsi:type="xsd:string">url</fileType>
                    <filename xsi:type="xsd:string">'.$url.'</filename>
                    <privateFile xsi:type="xsd:boolean">false</privateFile>
                    <publishToOwnerPortal xsi:type="xsd:boolean">true</publishToOwnerPortal>
                    <publishToTenantPortal xsi:type="xsd:boolean">true</publishToTenantPortal>
                </document>
                <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                    <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                </workOrder>
                </ser:attachDocumentToWorkOrder>
            </soapenv:Body>
            </soapenv:Envelope>';

        $response = $this->execute($xmlPayload2);

        Log::info('Work order conversation sent successfully!', [
            'Work order no' => $workOrder->work_order_no,
        ]);

        return $response;

    }

    private function buildCloseWorkOrderPayload($workOrder)
    {
        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int) $workOrder->portfolio_id;
        $buildingId = $workOrder->building_id;
        $location = $workOrder->location;

        $approvedDataXml = '';
            if ($workOrder->is_approved) {
                $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
                $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
                $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
            
                $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
                $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
                $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
            }



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
                                    <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                                    <building xsi:type="urn:Building">
                                        <ID xsi:type="xsd:long">'.$buildingId.'</ID>
                                    </building>
                                    <portfolio xsi:type="urn:Portfolio">
                                        <ID xsi:type="xsd:long">'.$portfolioId.'</ID>
                                    </portfolio>
                                    <location xsi:type="xsd:string">'.$location.'</location>
                                    <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                                    <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                                    <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                                    '.$approvedDataXml.'
                                    <status xsi:type="xsd:string">Closed</status>
                                </workOrder>
                            </ser:updateWorkOrder>
                        </soapenv:Body>
                    </soapenv:Envelope>
                ';

        $response = $this->execute($xmlPayload);

        // Log and return response status
        if ($response) {
            Log::info('Work order service status has been closed successfully!', [
                'Work order no' => $workOrder->work_order_no,
            ]);
        }
    }

    public function reOpenWorkOrder(object $workOrder)
    {
        try {
            $workorderId = $workOrder->propertyware_id;
            $portfolioId = (int) $workOrder->portfolio_id;
            $buildingId = $workOrder->building_id;

            $approvedDataXml = '';
            if ($workOrder->is_approved) {
                $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
                $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
                $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
            
                $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
                $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
                $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
            }

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
                            <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                            <building xsi:type="urn:Building">
                            <ID xsi:type="xsd:long">'.$buildingId.'</ID>
                            </building>
                            <portfolio xsi:type="urn:Portfolio">
                                <ID xsi:type="xsd:long">'.$portfolioId.'</ID>
                            </portfolio>
                            <location xsi:type="xsd:string">'.htmlspecialchars($workOrder->location, ENT_XML1, 'UTF-8').'</location>
                            <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                            <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                            <status xsi:type="xsd:string">Open</status>
                            <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                            '.$approvedDataXml.'
                        </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                    </soapenv:Envelope>
                ';

            $response = $this->execute($xmlPayload);

            // Log and return response status
            if ($response) {
                Log::info('Work order service status has been reopen successfully!', [
                    'Work order no' => $workOrder->work_order_no,
                ]);

                return true;
            }

            Log::error('Work order service status has been reopen failed!', [
                'Work order no' => $workOrder->work_order_no,

            ]);

            return false;

        } catch (\Exception $exception) {
            Log::error('Re-opening work order failed: '.$exception);

            return false;
        }

    }

    public function changeServiceStatusPropertyWare($workOrder, $servicestatusData)
    {
        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int) $workOrder->portfolio_id;
        $buildigId = $workOrder->building_id;
        $location = $workOrder->location;
        $serviceStatus = $servicestatusData->name;

        $approvedDataXml = '';
        if ($workOrder->is_approved) {
            $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
            $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
            $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
        
            $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
            $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
            $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
        }
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
                <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                <building xsi:type="urn:Building">
                <ID xsi:type="xsd:long">'.$buildigId.'</ID>
                </building>
                <portfolio xsi:type="urn:Portfolio">
                <ID xsi:type="xsd:long">'.$portfolioId.'</ID>
                </portfolio>
                <location xsi:type="xsd:string">'.$location.'</location>
                <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                <closingComments xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</closingComments>
                <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                '.$approvedDataXml.'
                <customFields xsi:type="pws:ArrayOf_tns1_CustomField" soapenc:arrayType="urn:CustomField[0]"
                    xmlns:pws="https://rcsppwwwweb001.realpage.com/pw/services/PWServices">
                    <customFields xsi:type="ns2:CustomField">
                        <fieldName xsi:type="xsd:string">Service Status</fieldName>
                        <value xsi:type="xsd:string">'.$serviceStatus.'</value>
                        </customFields>
                </customFields>
                </workOrder>
                </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

        $res = $this->execute($xmlPayload);

        // Log and return response status
        if ($res) {
            Log::info('Work order service status has been changed successfully!', [
                'Work order no' => $workOrder->work_order_no,
            ]);

            return true;
        }

        Log::error('Work order service status changed failed!', [
            'Work order no' => $workOrder->work_order_no,

        ]);

        return false;

    }

    public function changeWorkOrderVendors($workOrder, $vendorIDsXml)
    {
        $workorderId = $workOrder->propertyware_id;
        $portfolioId = (int) $workOrder->portfolio_id;
        $buildigId = $workOrder->building_id;
        $location = $workOrder->location;

        $approvedDataXml = '';
        if ($workOrder->is_approved) {
            $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
            $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
            $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
        
            $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
            $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
            $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
        }
        

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
                        <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                        <building xsi:type="urn:Building">
                        <ID xsi:type="xsd:long">'.$buildigId.'</ID>
                        </building>
                        <portfolio xsi:type="urn:Portfolio">
                        <ID xsi:type="xsd:long">'.$portfolioId.'</ID>
                        </portfolio>
                        <location xsi:type="xsd:string">'.$location.'</location>
                        <location xsi:type="xsd:string">'.htmlspecialchars($workOrder->location, ENT_XML1, 'UTF-8').'</location>
                        <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                        <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                        <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                        '.$approvedDataXml.'
                        <closingComments xsi:type="xsd:string">'.htmlspecialchars($workOrder->closing_comments ?? '', ENT_XML1, 'UTF-8').'</closingComments>
                        '.$vendorIDsXml.'
                    </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                </soapenv:Envelope>';

        // Execute SOAP request
        $res = $this->execute($xmlPayload);

        // Log and return response status
        if ($res) {
            Log::info('Work order vendor has been added successfully!', [
                'Work order no' => $workOrder->work_order_no,
            ]);

            return true;
        }

        Log::error('Work order vendor added failed!', [
            'Work order no' => $workOrder->work_order_no,
        ]);

        return false;
    }

    public function addVendorNotes($notes)
    {

        $workOrder = WorkOrder::find($notes->work_order_id);

        $xmlPayload = '
            <soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:ser="http://service.web.propertyware.realpage.com"
            xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
            <soapenv:Header/>
            <soapenv:Body>
            <ser:attachNoteToWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                <note xsi:type="urn:Note" xmlns:urn="urn:PWServices">
                    <clientData xsi:type="pws:ArrayOf_tns1_ClientDataItem"
                    soapenc:arrayType="urn:ClientDataItem[]"
                    xmlns:pws="http://localhost:8080/pw/services/PWServices"/>
                    <body xsi:type="xsd:string">'.$notes->body.'</body>
                    <date xsi:type="xsd:dateTime">'.date('Y-m-d').'</date>
                    <private xsi:type="xsd:boolean">0</private>
                    <subject xsi:type="xsd:string">'.$notes->subject.'</subject>
                </note>
                <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                    <clientData xsi:type="pws:ArrayOf_tns1_ClientDataItem"
                    soapenc:arrayType="urn:ClientDataItem[]"
                    xmlns:pws="http://localhost:8080/pw/services/PWServices"/>
                    <ID xsi:type="xsd:long">'.$workOrder->propertyware_id.'</ID>
                </workOrder>
            </ser:attachNoteToWorkOrder>
            </soapenv:Body>
            </soapenv:Envelope>';

        $response = $this->execute($xmlPayload);

        // Log and return response status
        if ($response) {
            Log::info('Vendor notes has been added successfully!', [
                'Work order no' => $workOrder->work_order_no,
            ]);

            return true;
        }

        Log::error('Vendor attachment upload failed!', [
            'Work order no' => $workOrder->work_order_no,
        ]);

        return false;

    }

    public function uploadVendorAttachment($workOrderId, $attachments)
    {
        try {

            if (! $attachments || ! $workOrderId) {
                throw new \Exception('Invalid work order ID or attachments.');
            }

            $workorder = WorkOrder::find($workOrderId);
            if (! $workorder) {
                throw new \Exception('Work order not found.');
            }
            $workorderId = $workorder->propertyware_id;

            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachments['filename']);

            $absolutePath = public_path('storage/attachments/'.basename($attachments['filename']));

            if (! file_exists($absolutePath)) {
                throw new \Exception('Attachment file does not exist: '.$absolutePath);
            }
            $fileContents = file_get_contents($absolutePath);
            $fileData = base64_encode($fileContents);

            // Sanitize and construct filename
            $title = $attachments['title'];
            $filePath = $attachments['filename'];

            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
            $sanitizedTitle = preg_replace('/[^a-zA-Z0-9-_]/', '_', $title);
            $filename = $sanitizedTitle.'_'.uniqid().'.'.$fileExtension;

            $xmlPayload = '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.web.propertyware.realpage.com"
                xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                <soapenv:Header/>
                <soapenv:Body>
                    <ser:attachDocumentToWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                        <document xsi:type="urn:Document" xmlns:urn="urn:PWServices">
                            <ID xsi:type="xsd:long">0</ID>
                            <description xsi:type="xsd:string">'.$attachments['title'].'</description>
                            <fileData xsi:type="xsd:string">'.$fileData.'</fileData>
                            <filename xsi:type="xsd:string">'.$filename.'</filename>
                            <privateFile xsi:type="xsd:boolean">false</privateFile>
                            <publishToOwnerPortal xsi:type="xsd:boolean">'.($attachments['is_publish_to_owner_portal'] == 1 ? 'true' : 'false').'</publishToOwnerPortal>
                            <publishToTenantPortal xsi:type="xsd:boolean">'.($attachments['is_publish_to_tenant_portal'] == 1 ? 'true' : 'false').'</publishToTenantPortal>
                        </document>
                        <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                            <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                            <!-- Include other work order properties here -->
                        </workOrder>
                    </ser:attachDocumentToWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

            // Execute SOAP request
            $res = $this->execute($xmlPayload);

            // Log and return response status
            if ($res) {
                Log::info('Vendor attachment has been uploaded successfully!', [
                    'Work order no' => $workorder->work_order_no,
                    'filename' => $filename,
                ]);

                return true;
            }

            Log::error('Vendor attachment upload failed!', [
                'Work order no' => $workorder->work_order_no,
                'filename' => $filename,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error in uploadVendorAttachment: '.$e->getMessage(), [
                'Work order no' => $workorder->work_order_no,
                'filename' => $attachments->filename ?? 'N/A',
            ]);

            return false;
        }
    }

    public function updateWorkOrderDetails($workOrder)
    {
        $cost_etimate = 0;
        $time_estimate = 0;
        $scheduled_end_date = null;

        try {
            if (! $workOrder) {
                throw new \Exception('Work order not found.');
            }
            $workOrder = WorkOrder::with('vendors')->find($workOrder->id);

            $workorderId = $workOrder->propertyware_id;

            foreach ($workOrder->vendors as $vendor) {
                $cost_etimate += $vendor->pivot->cost_estimate;
                $time_estimate += $vendor->pivot->time_estimate;

                if ($vendor->pivot->scheduled_end_date) {
                    $current_date = Carbon::parse($vendor->pivot->scheduled_end_date);

                    if (! $scheduled_end_date || $current_date->gt($scheduled_end_date)) {
                        $scheduled_end_date = $current_date;
                    }
                }
            }

            $approvedDataXml = '';
            if ($workOrder->is_approved) {
                $formattedDate = Carbon::parse($workOrder->approved_date)->toIso8601String();
                $approvalComment = htmlspecialchars($workOrder->approval_comments ?? '');
                $approvedBy = htmlspecialchars($workOrder->approved_by ?? '');
            
                $approvedDataXml  = '<approvedDate>' . $formattedDate . '</approvedDate>';
                $approvedDataXml .= '<approved xsi:type="xsd:boolean">' . ($workOrder->is_approved ? 'true' : 'false') . '</approved>';
                $approvedDataXml .= '<approvalComment xsi:type="xsd:string">' . $approvalComment . '</approvalComment>';
                $approvedDataXml .= '<approvedBy xsi:type="xsd:string">' . $approvedBy . '</approvedBy>';
            }

            $xmlPayload = '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.web.propertyware.realpage.com"
                xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
                <soapenv:Header/>
                <soapenv:Body>
                <ser:updateWorkOrder soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                    <workOrder xsi:type="urn:WorkOrder" xmlns:urn="urn:PWServices">
                        <ID xsi:type="xsd:long">'.$workorderId.'</ID>
                        <building xsi:type="urn:Building">
                            <ID xsi:type="xsd:long">'.(int) $workOrder->building_id.'</ID>
                        </building>
                        <portfolio xsi:type="urn:Portfolio">
                            <ID xsi:type="xsd:long">'.(int) $workOrder->portfolio_id.'</ID>
                        </portfolio>
                        <location xsi:type="xsd:string">'.htmlspecialchars($workOrder->location, ENT_XML1, 'UTF-8').'</location>
                        <costEstimate xsi:type="xsd:double">'.(float) ($cost_etimate ?? 0).'</costEstimate>
                        <hourEstimate xsi:type="xsd:double">'.(float) ($time_estimate ?? 0).'</hourEstimate>
                        <scheduledEndDate xsi:type="xsd:date">'.$scheduled_end_date.'</scheduledEndDate>
                        
                        <location xsi:type="xsd:string">'.htmlspecialchars($workOrder->location, ENT_XML1, 'UTF-8').'</location>
                        <category xsi:type="xsd:string">'.htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8').'</category>
                        <description xsi:type="xsd:string">'.htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8').'</description>
                        <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                        '.$approvedDataXml.'
                    </workOrder>
                </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

            // Execute SOAP request
            $res = $this->execute($xmlPayload);

            // Log and return response status
            if ($res) {
                Log::info('Vendor updating work order details has been successfully!', [
                    'Work order no' => $workOrder->work_order_no,
                ]);

                return true;
            }

            Log::error('Vendor updating work order failed!', [
                'Work order no' => $workOrder->work_order_no,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error in updating work order: '.$e->getMessage(), [
                'workOrderId' => $workorderId,
            ]);

            return false;
        }
    }

    public function execute($xmlPayload)
    {

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml',
                'SOAPAction: ""',
            ],
            CURLOPT_USERPWD => $this->username.':'.$this->password,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 300,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, // 👈 Force TLS 1.2
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_VERBOSE => app()->environment('local') ? false : true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // Log the request and response
        // Log::debug('SOAP Request:', ['payload' => $xmlPayload]);
        Log::debug('SOAP Response:', [
            'http_code' => $httpCode,
            'curl_error' => curl_error($curl),
            'curl_errno' => curl_errno($curl),
        ]);

        if (curl_errno($curl)) {
            $errorNo = curl_errno($curl);
            $errorMsg = curl_error($curl);

            Log::error('cURL Error Details:', [
                'errno' => $errorNo,
                'error' => $errorMsg,
                'http_code' => $httpCode,
                'full_url' => $this->url,
            ]);

            curl_close($curl);

            return [
                'success' => false,
                'error' => 'CURL_ERROR',
                'message' => $errorMsg,
            ];
        }

        // Check for SOAP faults in the response
        if (strpos($response, '<soapenv:Fault>') != false) {
            $faultString = $this->extractFaultString($response);
            Log::error('SOAP Fault: '.$faultString);

            curl_close($curl);

            return [
                'success' => false,
                'error' => 'SOAP_FAULT',
                'message' => $faultString,
            ];
        }

        curl_close($curl);

        return [
            'success' => true,
            'response' => $response,
        ];
    }

    protected function extractFaultString($xmlResponse)
    {
        return $xmlResponse;
    }

    public function initiate()
    {

        $options = [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'trace' => 1,
            'login' => $this->username,
            'password' => $this->password,
            'connection_timeout' => 5000,
            'exceptions' => true,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
        ];

        $client = new \SoapClient($this->url.'?wsdl', $options);

        return $client;
    }
}
