<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PropertyWareService
{
    protected $url;

    protected $username;

    protected $password;

    protected $client_id;

    protected $secret_key;

    protected $system_id;

    protected $headers;

    public function __construct()
    {
        // Load configuration from config/services.php
        $this->url = config('services.propertyware.url');
        $this->username = config('services.propertyware.username');
        $this->password = config('services.propertyware.password');
        $this->client_id = env('PROPERTYWARE_CLIENT_ID');
        $this->secret_key = env('PROPERTYWARE_CLIENT_SECRET_KEY');
        $this->system_id = env('PROPERTYWARE_SYSTEM_ID');

        $this->headers = [
            'x-propertyware-client-id' => env('PROPERTYWARE_CLIENT_ID'),
            'x-propertyware-client-secret' => env('PROPERTYWARE_CLIENT_SECRET_KEY'),
            'x-propertyware-system-id' => env('PROPERTYWARE_SYSTEM_ID'),
        ];

        if (empty($this->url) || empty($this->username) || empty($this->password)) {
            Log::error('PropertyWare API: Missing credentials. Skipping API connection.');

            return; // Avoid crashing during deployment
        }
    }

    public function getWorkOrder($workOrderId)
    {
        try {
            $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders/'.$workOrderId);
            if ($response->status() == 200) {
                return $response->json();
            } else {
                Log::error('Error retrieving workorder', [
                    'error_details' => [
                        'status_code' => $response->status(),
                        'body' => $response->body(),
                    ],
                ]);

                return false;
            }
        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function getWorkOrders()
    {
        try {

            $client = $this->initiate();
            $allWorkOrders = [];

            for ($pageNumber = 1; $pageNumber <= 10; $pageNumber++) {
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

    /**
     * Stream SOAP work orders page-by-page to avoid loading all pages into memory.
     *
     * @param  callable(array<int, mixed>): void  $callback
     */
    public function streamWorkOrders(callable $callback, int $maxPages = 5): void
    {
        try {
            $client = $this->initiate();

            for ($pageNumber = 1; $pageNumber <= $maxPages; $pageNumber++) {
                $params = [
                    'pageNumber' => $pageNumber,
                    'orderByNewestFirst' => 1,
                ];

                $response = $client->getWorkOrders($params);

                if (empty($response)) {
                    break;
                }

                $orders = json_decode(json_encode($response), true);
                if (empty($orders)) {
                    break;
                }

                $callback($orders);
            }
        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());
            throw $e;
        }
    }

    public function getWorkOrdersViaRestAPI()
    {
        try {

            $allWorkOrders = [];
            $limit = 500; // PropertyWare API max limit per request
            $totalToFetch = 3000;
            $numberOfRequests = (int) ceil($totalToFetch / $limit);

            for ($i = 0; $i < $numberOfRequests; $i++) {
                $offset = $i * $limit;

                $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders', [
                    'includeCustomFields' => 'true',
                    'orderby' => 'createddate DESC',
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                if ($response->status() == 200) {
                    $workOrders = $response->json();

                    if (empty($workOrders)) {
                        break; // No more results
                    }

                    $allWorkOrders = array_merge($allWorkOrders, $workOrders);

                    Log::info('Success in retrieving work orders', [
                        'batch' => $i + 1,
                        'offset' => $offset,
                        'count' => count($workOrders),
                    ]);

                    // If we got fewer results than the limit, we've reached the end
                    if (count($workOrders) < $limit) {
                        break;
                    }
                } else {
                    Log::error('Error retrieving Work Orders', [
                        'error' => 'Unable to retrieve work orders',
                        'error_details' => [
                            'status_code' => $response->status(),
                            'body' => $response->body(),
                            'offset' => $offset,
                        ],
                    ]);

                    return false;
                }
            }

            Log::info('Total work orders retrieved', [
                'total' => count($allWorkOrders),
            ]);

            return $allWorkOrders;

        } catch (Exception $e) {
            Log::error('REST API request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }
    }

    public function getWorkOrdersViaRestAPIWithTotalCap(int $totalToFetch = 3000, int $limit = 500)
    {
        try {

            $allWorkOrders = [];
            $numberOfRequests = (int) ceil($totalToFetch / $limit);
            $stopReason = 'completed_loop';

            Log::info('PropertyWare capped work order fetch started', [
                'target_total' => $totalToFetch,
                'batch_limit' => $limit,
                'planned_requests' => $numberOfRequests,
            ]);

            for ($i = 0; $i < $numberOfRequests; $i++) {
                $offset = $i * $limit;
                $remaining = $totalToFetch - count($allWorkOrders);
                if ($remaining <= 0) {
                    $stopReason = 'target_reached_before_request';
                    break;
                }

                $requestLimit = min($limit, $remaining);

                $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders', [
                    'includeCustomFields' => 'true',
                    'orderby' => 'createddate DESC',
                    'limit' => $requestLimit,
                    'offset' => $offset,
                ]);

                if ($response->status() == 200) {
                    $workOrders = $response->json();

                    if (empty($workOrders)) {
                        $stopReason = 'empty_response';
                        break; // No more results
                    }

                    // Keep total strictly capped at $totalToFetch.
                    if (count($workOrders) > $remaining) {
                        $workOrders = array_slice($workOrders, 0, $remaining);
                    }

                    $allWorkOrders = array_merge($allWorkOrders, $workOrders);

                    Log::info('Success in retrieving work orders', [
                        'batch' => $i + 1,
                        'offset' => $offset,
                        'requested_limit' => $requestLimit,
                        'count' => count($workOrders),
                        'cumulative_total' => count($allWorkOrders),
                    ]);

                    // If we got fewer results than the limit, we've reached the end
                    if (count($workOrders) < $requestLimit) {
                        $stopReason = 'partial_batch_returned';
                        break;
                    }
                } else {
                    Log::error('Error retrieving Work Orders', [
                        'error' => 'Unable to retrieve work orders',
                        'error_details' => [
                            'status_code' => $response->status(),
                            'body' => $response->body(),
                            'offset' => $offset,
                            'requested_limit' => $requestLimit,
                            'target_total' => $totalToFetch,
                            'retrieved_so_far' => count($allWorkOrders),
                        ],
                    ]);

                    return false;
                }
            }

            Log::info('Total work orders retrieved', [
                'total' => count($allWorkOrders),
                'target_total' => $totalToFetch,
                'is_target_reached' => count($allWorkOrders) >= $totalToFetch,
                'stop_reason' => $stopReason,
            ]);

            if (count($allWorkOrders) < $totalToFetch) {
                Log::warning('PropertyWare returned fewer work orders than requested cap', [
                    'target_total' => $totalToFetch,
                    'retrieved_total' => count($allWorkOrders),
                    'missing_total' => $totalToFetch - count($allWorkOrders),
                    'stop_reason' => $stopReason,
                ]);
            }

            return array_slice($allWorkOrders, 0, $totalToFetch);

        } catch (Exception $e) {
            Log::error('REST API request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    /**
     * Stream REST work orders in fixed-size batches.
     *
     * @param  callable(array<int, mixed>): void  $callback
     */
    public function streamWorkOrdersViaRestAPI(callable $callback, int $limit = 500): void
    {
        try {
            $offset = 0;

            while (true) {
                $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders', [
                    'includeCustomFields' => 'true',
                    'orderby' => 'createddate DESC',
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

                if (! $response->successful()) {
                    Log::error('Error retrieving Work Orders', [
                        'error' => 'Unable to retrieve work orders',
                        'error_details' => [
                            'status_code' => $response->status(),
                            'body' => $response->body(),
                            'offset' => $offset,
                        ],
                    ]);
                    break;
                }

                $workOrders = $response->json();
                if (empty($workOrders)) {
                    break;
                }

                $callback($workOrders);

                $count = count($workOrders);
                Log::info('Success in retrieving work orders', [
                    'offset' => $offset,
                    'count' => $count,
                ]);

                $offset += $limit;
                if ($count < $limit) {
                    break;
                }

                unset($workOrders, $response);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } catch (Exception $e) {
            Log::error('REST API request failed: '.$e->getMessage());
            throw $e;
        }
    }

    public function getBuilding($buildingId)
    {
        try {

            $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/buildings/'.$buildingId);

            if ($response->status() == 200) {
                return $response->json();
            } else {
                Log::error('Error retrieving building', [
                    'error_details' => [
                        'status_code' => $response->status(),
                        'body' => $response->body(),
                    ],
                ]);

                return false;
            }

        } catch (Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return 'Error: '.$e->getMessage();
        }

    }

    public function getPortfolio($portfolioId)
    {
        try {

            $response = Http::withHeaders($this->headers)->get('https://api.propertyware.com/pw/api/rest/v1/portfolios/'.$portfolioId);

            if ($response->status() == 200) {
                return $response->json();
            } else {
                Log::error('Error retrieving portfolio', [
                    'error_details' => [
                        'status_code' => $response->status(),
                        'body' => $response->body(),
                    ],
                ]);

                return false;
            }

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

    public function getWorkOrderById($workOrderNo)
    {
        try {
            $client = $this->initiate();

            $response = $client->getWorkOrder($workOrderNo);

            return json_encode($response);

        } catch (\Exception $e) {
            Log::error('SOAP request failed: '.$e->getMessage());

            return [];
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
        $response = Http::withHeaders($this->headers)->patch('https://api.propertyware.com/pw/api/rest/v1/workorders/'.$workOrder->propertyware_id, [
            'authorizedToEnter' => strtoupper(str_replace(' ', '', $workOrder->authorized_to_enter)),
            'buildingID' => $workOrder->building_id,
            'category' => $workOrder->category,
            'costEstimate' => $workOrder->cost_estimate,
            'dateToEnter' => $workOrder->date_to_enter ? Carbon::parse($workOrder->date_to_enter)->format('Y-m-d') : '',
            'description' => $workOrder->description,
            'hourEstimate' => $workOrder->hour_estimate,
            'priority' => strtoupper($workOrder->priority),
            'requiredMaterials' => $workOrder->required_materials,
            'scheduledEndDate' => $workOrder->scheduled_end_date ? Carbon::parse($workOrder->scheduled_end_date)->format('Y-m-d') : '',
            'source' => $workOrder->source,
            'specificLocation' => $workOrder->specific_location,
            'startDate' => $workOrder->start_date ? Carbon::parse($workOrder->start_date)->format('Y-m-d') : '',
            'type' => $workOrder->type,
        ]);

        if ($response->status() == 200) {
            Log::info('Success in updating work order', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $response->status(),
                'headers' => $response->headers(),
            ]);
        }

        $res = Http::withHeaders($this->headers)->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
            'entityId' => $workOrder->propertyware_id,
            'fieldSetDTOS' => [
                [
                    'name' => 'Management Plan',
                    'value' => $workOrder?->management_plan ?? '',
                ],
                [
                    'name' => 'Additional work needed- Reschedule',
                    'value' => $workOrder?->additional_work_needed_reschedule ?? '',
                ],
                [
                    'name' => 'Zone',
                    'value' => $workOrder?->zone,
                ],
            ],
        ]);

        $this->approvedWorkOrder($workOrder);

        if ($res->status() == 200) {
            Log::info('Success in updating work order', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $res->status(),
            ]);

            return true;
        } else {
            Log::error('Error updating Work Order', [
                'error' => 'Unable to update work order',
                'error_details' => [
                    'status_code' => $response->status(),
                    'body' => $response->body(),
                ],
            ]);

            return false;
        }
    }

    public function updateServiceStatus(object $workOrder, object $service_status)
    {
        try {
            $response = Http::withHeaders($this->headers)->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
                'entityId' => $workOrder->propertyware_id,
                'fieldSetDTOS' => [
                    [
                        'name' => 'Service Status',
                        'value' => $service_status->name,
                    ],
                ],
            ]);

            if ($response->status() == 200) {
                Log::info('Success in updating work order service status', [
                    'work order' => $workOrder->work_order_no,
                    'status_code' => $response->status(),
                    'headers' => $response->headers(),
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
            // Load vendors for time card entries
            $workOrder->load('vendors');

            // Build time card entries for each vendor
            $timeCardEntries = [];
            if ($workOrder->vendors->isNotEmpty()) {
                foreach ($workOrder->vendors as $vendor) {
                    $timeCardEntries[] = [
                        'vendorID' => $vendor->propertyware_id ?? $vendor->id,
                        'comments' => 'Work completed',
                        'hours' => 1.0,
                        'hourlyRate' => 1.00,
                    ];
                }
            } else {
                // If no vendors assigned, we still need at least one entry for the API
                // Use a default/placeholder vendor if available
                Log::warning('No vendors assigned to work order, cannot close properly', [
                    'work_order_no' => $workOrder->work_order_no,
                ]);

                return false;
            }

            // Build the close work order payload according to PropertyWare API spec
            $payload = [
                'category' => $workOrder->category,
                'comments' => $workOrder->closing_comments ?? 'Work order closed',
                'completedDate' => $workOrder->completed_date ? Carbon::parse($workOrder->completed_date)->format('Y-m-d') : now()->format('Y-m-d'),
                'startDate' => $workOrder->start_date ? Carbon::parse($workOrder->start_date)->format('Y-m-d') : ($workOrder->completed_date ? Carbon::parse($workOrder->completed_date)->format('Y-m-d') : now()->format('Y-m-d')),
                'timeCardEntryDTOS' => $timeCardEntries,
            ];

            // Use REST API to close work order with proper payload
            $response = Http::withHeaders($this->headers)
                ->put("https://api.propertyware.com/pw/api/rest/v1/workorders/closeworkorder/{$workOrder->propertyware_id}", $payload);

            if (! $response->successful()) {
                Log::error('Failed to close work order via REST API', [
                    'work_order_no' => $workOrder->work_order_no,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'payload' => $payload,
                ]);

                return false;
            }

            // Update the custom field "Service Status" to "Closed"
            Http::withHeaders($this->headers)->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
                'entityId' => $workOrder->propertyware_id,
                'fieldSetDTOS' => [
                    [
                        'name' => 'Service Status',
                        'value' => 'Closed',
                    ],
                ],
            ]);

            // Attach conversation URL to work order
            $this->buildAttachDocumentPayload($workOrder, $url);

            Log::info('Work order closed successfully', [
                'work_order_no' => $workOrder->work_order_no,
            ]);

            return true;

        } catch (\Exception $exception) {
            Log::error('Closing work order failed: '.$exception->getMessage(), [
                'work_order_no' => $workOrder->work_order_no,
                'trace' => $exception->getTraceAsString(),
            ]);

            return false;
        }

    }

    private function buildAttachDocumentPayload($workOrder, $url)
    {
        $workorderId = $workOrder->propertyware_id;

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

    public function reOpenWorkOrder(object $workOrder)
    {
        try {
            // Use REST API PATCH to update status and clear completion date
            $response = Http::withHeaders($this->headers)
                ->patch("https://api.propertyware.com/pw/api/rest/v1/workorders/{$workOrder->propertyware_id}", [
                    'status' => 'Open',
                    'completedDate' => null,
                ]);

            if (! $response->successful()) {
                Log::error('Failed to reopen work order via REST API', [
                    'work_order_no' => $workOrder->work_order_no,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            // Update the custom field "Service Status" to "New"
            Http::withHeaders($this->headers)->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
                'entityId' => $workOrder->propertyware_id,
                'fieldSetDTOS' => [
                    [
                        'name' => 'Service Status',
                        'value' => 'New',
                    ],
                ],
            ]);

            Log::info('Work order reopened successfully', [
                'work_order_no' => $workOrder->work_order_no,
            ]);

            return true;

        } catch (\Exception $exception) {
            Log::error('Re-opening work order failed: '.$exception->getMessage(), [
                'work_order_no' => $workOrder->work_order_no,
                'trace' => $exception->getTraceAsString(),
            ]);

            return false;
        }

    }

    public function changeServiceStatusPropertyWare($workOrder, $servicestatusData)
    {

        $response = Http::withHeaders($this->headers)->put('https://api.propertyware.com/pw/api/rest/v1/workorders/customfields', [
            'entityId' => $workOrder->propertyware_id,
            'fieldSetDTOS' => [
                [
                    'name' => 'Service Status',
                    'value' => $servicestatusData->name,
                ],
            ],
        ]);

        if ($response->status() == 200) {
            Log::info('Work order service status has been changed successfully', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $response->status(),
                'headers' => $response->headers(),

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

        // Build SOAP payload without location field to avoid validation errors
        // PropertyWare's REST API returns truncated locations (27 chars) but SOAP validates against full location

        $location = htmlspecialchars($workOrder->location ?? '', ENT_XML1, 'UTF-8');

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
                        <type xsi:type="xsd:string">'.htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8').'</type>
                        '.$vendorIDsXml.'
                    </workOrder>
                    </ser:updateWorkOrder>
                    </soapenv:Body>
                </soapenv:Envelope>';

        // Execute SOAP request
        $res = $this->execute($xmlPayload);

        // Check if SOAP request failed
        if (! $res['success']) {
            Log::error('Failed to update work order vendors in PropertyWare', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'category' => $workOrder->category,
                'type' => $workOrder->type,
                'building_id' => $buildigId,
                'portfolio_id' => $portfolioId,
                'error' => $res['error'],
                'message' => $res['message'],
            ]);

            throw new \Exception('PropertyWare API Error: '.$res['message']);
        }

        if ($workOrder->is_approved) {
            $this->approvedWorkOrder($workOrder);
        }

        $vendor = DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->first();
        $vendorName = Vendor::find($vendor->vendor_id);

        if ($vendorName->name == 'Texas Home Maintenance Pros') {
            Http::post('https://n8n.srv902502.hstgr.cloud/webhook/create-job', [
                'work_order_no' => $workOrder->work_order_no,
            ]);
        }

        Log::info('Work order vendor has been added successfully!', [
            'Work order no' => $workOrder->work_order_no,
        ]);

        return true;
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

    public function uploadVendorAttachment($workOrderId, $attachment)
    {
        try {

            $workOrder = WorkOrder::find($workOrderId);

            $absolutePath = public_path('storage/attachments/'.basename($attachment['filename']));

            if (! file_exists($absolutePath)) {
                throw new \Exception('File does not exist: '.$absolutePath);
            }

            $title = $attachment['title'] ?? 'Invoice';

            // Replace spaces with underscores
            $cleaned = str_replace(' ', '_', $title);

            // Replace slashes and other unsafe characters with dashes or remove them
            $cleaned = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $cleaned);

            // Optional: remove anything that's not alphanumeric, underscore, or dash
            $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleaned);

            // Ensure filename is always unique
            $fileName = $sanitized.'_'.now()->format('Ymd_His').'.'.pathinfo($attachment['filename'], PATHINFO_EXTENSION);

            // Read file once (fixed duplicate read)
            $fileContents = file_get_contents($absolutePath);

            $formFields = [
                'entityId' => $workOrder->propertyware_id,
                'entityType' => 'Work Order',
            ];

            $response = Http::withHeaders($this->headers)
                ->attach('file', $fileContents, $fileName)
                ->post('https://api.propertyware.com/pw/api/rest/v1/docs', $formFields);

            // Handle the response
            if ($response->successful()) {

                $postData = $response->json();

                $putResponse = Http::withHeaders($this->headers)
                    ->put('https://api.propertyware.com/pw/api/rest/v1/docs/'.$postData['id'], [
                        'fileName' => $fileName,
                        'description' => $attachment['title'],
                        'publishToOwnerPortal' => $attachment['is_publish_to_owner_portal'] ? 'true' : 'false',
                        'publishToTenantPortal' => $attachment['is_publish_to_tenant_portal'] ? 'true' : 'false',
                    ]);

                if (! $putResponse->successful()) {
                    Log::error('Failed to update attachment metadata', [
                        'doc_id' => $postData['id'],
                        'status' => $putResponse->status(),
                        'body' => $putResponse->body(),
                    ]);

                    return false;
                }

                Log::info('Work order attachment has been uploaded successfully!', [
                    'Work order no' => (int) $workOrder->work_order_no,
                    'filename' => $fileName,
                    'doc_id' => $postData['id'],
                ]);

                return true;
            }

            Log::error('Error uploading work order attachment', [
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $response->json(),
                'fileName' => $fileName,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Error uploading work order attachment: '.$e->getMessage());

            return false;
        }
    }

    public function uploadVendorInvoice($workOrderId, $invoice)
    {
        try {
            $workOrder = WorkOrder::find($workOrderId);
            if (! $workOrder) {
                throw new \Exception("Work order not found: $workOrderId");
            }

            $absolutePath = public_path('storage/invoices/'.basename($invoice->filename));
            if (! file_exists($absolutePath)) {
                throw new \Exception("File does not exist: $absolutePath");
            }

            $formFields = [
                'entityId' => $workOrder->propertyware_id,
                'entityType' => 'Work Order',
            ];

            $title = $invoice->title ?? 'Invoice';

            // Replace spaces with underscores
            $cleaned = str_replace(' ', '_', $title);
            // Replace slashes and other unsafe characters with dashes or remove them
            $cleaned = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $cleaned);
            // Optional: remove anything that's not alphanumeric, underscore, or dash
            $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cleaned);

            // Ensure filename is always unique
            $fileName = $sanitized.'_'.now()->format('Ymd_His').'.'.pathinfo($invoice->filename, PATHINFO_EXTENSION);

            $fileContents = file_get_contents($absolutePath);

            Log::debug('Invoices data', [
                'fileName' => $fileName,
                'formFields' => $formFields,
                'path' => $absolutePath,
            ]);

            $response = Http::withHeaders($this->headers)
                ->attach('file', $fileContents, $fileName)
                ->post('https://api.propertyware.com/pw/api/rest/v1/docs', $formFields);

            if ($response->successful()) {
                $postData = $response->json();

                $putResponse = Http::withHeaders($this->headers)
                    ->put("https://api.propertyware.com/pw/api/rest/v1/docs/{$postData['id']}", [
                        'fileName' => $fileName,
                        'description' => $invoice->title,
                        'publishToOwnerPortal' => $invoice->is_publish_to_owner_portal ? 'true' : 'false',
                        'publishToTenantPortal' => $invoice->is_publish_to_tenant_portal ? 'true' : 'false',
                    ]);

                if (! $putResponse->successful()) {
                    Log::error('Failed to update invoice metadata', [
                        'doc_id' => $postData['id'],
                        'status' => $putResponse->status(),
                        'body' => $putResponse->body(),
                    ]);

                    return false;
                }

                Log::info('Invoice uploaded successfully', [
                    'work_order_no' => $workOrder->work_order_no,
                    'doc_id' => $postData['id'],
                    'filename' => $fileName,
                ]);

                $workOrder = WorkOrder::find($workOrderId);

                activity()
                    ->performedOn($invoice)
                    ->causedBy(auth()->user()) // so we know who uploaded
                    ->event('invoice_uploaded')
                    ->withProperties([
                        'filename' => $invoice->title,
                        'work_order_id' => $workOrder->id,
                    ])
                    ->log('Work Order #'.$workOrder->work_order_no.' - Invoice uploaded');

                return true;
            }

            Log::error('Error uploading invoice', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Exception uploading invoice: '.$e->getMessage());

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

            // Fetch current location from PropertyWare to ensure accuracy
            $pwWorkOrder = $this->getWorkOrder($workorderId);
            if ($pwWorkOrder && isset($pwWorkOrder['location']) && ! empty($pwWorkOrder['location'])) {
                $location = $pwWorkOrder['location'];

                // Update local database if different
                if ($workOrder->location !== $location) {
                    $workOrder->location = $location;
                    $workOrder->save();

                    Log::info('Synchronized work order location from PropertyWare', [
                        'work_order_no' => $workOrder->work_order_no,
                        'location' => $location,
                    ]);
                }
            } elseif (! $workOrder->location || trim($workOrder->location) === '') {
                Log::warning('Work order location is empty, skipping PropertyWare sync', [
                    'work_order_no' => $workOrder->work_order_no,
                    'work_order_id' => $workOrder->id,
                ]);

                return false;
            }

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

            $location = htmlspecialchars($workOrder->location ?? '', ENT_XML1, 'UTF-8');
            $category = htmlspecialchars($workOrder->category ?? '', ENT_XML1, 'UTF-8');
            $description = htmlspecialchars($workOrder->description ?? '', ENT_XML1, 'UTF-8');
            $type = htmlspecialchars($workOrder->type ?? '', ENT_XML1, 'UTF-8');

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
                        <location xsi:type="xsd:string">'.$location.'</location>
                        <costEstimate xsi:type="xsd:double">'.(float) ($cost_etimate ?? 0).'</costEstimate>
                        <hourEstimate xsi:type="xsd:double">'.(float) ($time_estimate ?? 0).'</hourEstimate>
                        <scheduledEndDate xsi:type="xsd:date">'.$scheduled_end_date.'</scheduledEndDate>
                        <category xsi:type="xsd:string">'.$category.'</category>
                        <description xsi:type="xsd:string">'.$description.'</description>
                        <type xsi:type="xsd:string">'.$type.'</type>
                    </workOrder>
                </ser:updateWorkOrder>
                </soapenv:Body>
                </soapenv:Envelope>';

            // Execute SOAP request
            $res = $this->execute($xmlPayload);

            $this->approvedWorkOrder($workOrder);

            // Log and return response status
            if ($res && isset($res['success']) && $res['success']) {
                Log::info('Vendor updating work order details has been successfully!', [
                    'work_order_no' => $workOrder->work_order_no,
                ]);

                return true;
            }

            Log::error('Vendor updating work order failed!', [
                'work_order_no' => $workOrder->work_order_no,
                'response' => $res,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error in updating work order: '.$e->getMessage(), [
                'workOrderId' => $workorderId ?? null,
                'work_order_no' => $workOrder->work_order_no ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function updateWorkOrderVendorEstimates($workOrder, $syncApproval = false)
    {
        try {
            if (! $workOrder) {
                throw new \Exception('Work order not found.');
            }

            $cost_etimate = 0;
            $time_estimate = 0;
            $scheduled_end_date = null;

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

            // Send PATCH request to PropertyWare REST API
            $response = Http::withHeaders($this->headers)
                ->patch('https://api.propertyware.com/pw/api/rest/v1/workorders/'.$workOrder->propertyware_id,
                    [
                        'costEstimate' => $cost_etimate,
                        'hourEstimate' => $time_estimate,
                        'scheduledEndDate' => $scheduled_end_date ? Carbon::parse($scheduled_end_date)->format('Y-m-d') : null,
                    ]);

            // Sync approval status if requested
            if ($syncApproval) {
                $this->approvedWorkOrder($workOrder);
            }

            if ($response->status() == 200) {
                Log::info('Work order synced to PropertyWare successfully', [
                    'work order' => $workOrder->work_order_no,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            Log::warning('Failed to sync work order to PropertyWare', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error syncing work order to PropertyWare: '.$e->getMessage(), [
                'work_order_id' => $workOrder->propertyware_id ?? null,
                'work_order_no' => $workOrder->work_order_no ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function updateWorkOrderServiceSchedule($workOrder, $syncApproval = false)
    {
        try {
            if (! $workOrder) {
                throw new \Exception('Work order not found.');
            }

            // Send PATCH request to PropertyWare REST API
            $response = Http::withHeaders($this->headers)
                ->patch('https://api.propertyware.com/pw/api/rest/v1/workorders/'.$workOrder->propertyware_id,
                    [
                        'startDate' => $workOrder->start_date ? Carbon::parse($workOrder->start_date)->format('Y-m-d') : null,
                        'scheduledEndDate' => $workOrder->scheduled_end_date ? Carbon::parse($workOrder->scheduled_end_date)->format('Y-m-d') : null,
                    ]);

            // Sync approval status if requested
            if ($syncApproval) {
                $this->approvedWorkOrder($workOrder);
            }

            if ($response->status() == 200) {
                Log::info('Work order synced to PropertyWare successfully', [
                    'work order' => $workOrder->work_order_no,
                    'status_code' => $response->status(),
                ]);

                return true;
            }

            Log::warning('Failed to sync work order to PropertyWare', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $response->status(),
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error syncing work order to PropertyWare: '.$e->getMessage(), [
                'work_order_id' => $workOrder->propertyware_id ?? null,
                'work_order_no' => $workOrder->work_order_no ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function approvedWorkOrder($workOrder): void
    {
        $client = $this->initiate();

        if ($workOrder->is_approved) {

            $work_order_no = $workOrder->work_order_no;
            $approved = $workOrder->is_approved;
            $approvedDate = $workOrder->approved_date ?? '';
            $approvalComment = $workOrder->approval_comments ?? '';

            $client->approveWorkOrder($work_order_no, $approved, $approvedDate, $approvalComment);

            Log::info('Work order approval has been added!', [
                'Work order no' => $work_order_no,
            ]);

        }

    }

    public function execute($xmlPayload)
    {

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->url.'?wsdl',
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

            return [
                'success' => false,
                'error' => 'SOAP_FAULT',
                'message' => $faultString,
            ];
        }

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
