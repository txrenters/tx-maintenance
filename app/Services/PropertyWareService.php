<?php

namespace App\Services;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Build a PropertyWare document request that retries transient failures —
     * connection errors and 5xx responses (e.g. the 503 PropertyWare returns
     * during maintenance/capacity windows) — before giving up. With throw:false
     * the final failed response is returned so callers keep degrading gracefully.
     */
    private function documentRequest(): PendingRequest
    {
        return Http::withHeaders($this->headers)
            ->retry(3, 300, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && in_array($exception->response->status(), [500, 502, 503, 504], true));
            }, throw: false);
    }

    /**
     * Retrieve the metadata for a single PropertyWare document.
     *
     * @return array<string, mixed>|null
     */
    public function getDocument($documentId): ?array
    {
        try {
            $response = $this->documentRequest()
                ->get('https://api.propertyware.com/pw/api/rest/v1/docs/'.$documentId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Error retrieving document', [
                'document_id' => $documentId,
                'status_code' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (Exception $e) {
            Log::error('PropertyWare getDocument failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * List the documents attached to a work order in PropertyWare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWorkOrderDocuments($workOrderId): array
    {
        try {
            $response = $this->documentRequest()
                ->get('https://api.propertyware.com/pw/api/rest/v1/docs', [
                    'entityType' => 'WORK_ORDER',
                    'entityId' => $workOrderId,
                ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::error('Error retrieving work order documents', [
                'work_order_pw_id' => $workOrderId,
                'status_code' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (Exception $e) {
            Log::error('PropertyWare getWorkOrderDocuments failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Download the raw file content of a PropertyWare document.
     *
     * @return array{content: string, mime: string}|null
     */
    public function downloadDocument($documentId): ?array
    {
        try {
            $response = $this->documentRequest()
                ->get('https://api.propertyware.com/pw/api/rest/v1/docs/'.$documentId.'/download');

            if ($response->successful()) {
                return [
                    'content' => $response->body(),
                    'mime' => $response->header('Content-Type') ?: 'application/octet-stream',
                ];
            }

            Log::error('Error downloading document', [
                'document_id' => $documentId,
                'status_code' => $response->status(),
            ]);

            return null;
        } catch (Exception $e) {
            Log::error('PropertyWare downloadDocument failed: '.$e->getMessage());

            return null;
        }
    }

    public function getWorkOrders()
    {
        try {

            $client = $this->initiate();
            $allWorkOrders = [];

            for ($pageNumber = 1; $pageNumber <= 20; $pageNumber++) {
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

    public function getWorkOrdersViaRestAPI()
    {
        try {

            $allWorkOrders = [];
            $limit = 500; // PropertyWare API max limit per request
            $totalToFetch = 5000;
            $numberOfRequests = ceil($totalToFetch / $limit);

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

    /**
     * Retrieve a single vendor from PropertyWare by its ID (REST: GET /vendors/{id}).
     * Uses the same API-key headers ($this->headers) as the other REST calls.
     *
     * @return array<string, mixed>|null
     */
    public function getVendor($vendorId): ?array
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->get('https://api.propertyware.com/pw/api/rest/v1/vendors/'.$vendorId, [
                    'includeCustomFields' => 'true',
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Error retrieving vendor from PropertyWare', [
                'vendor_id' => $vendorId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (Throwable $e) {
            Log::error('PropertyWare getVendor failed: '.$e->getMessage(), ['vendor_id' => $vendorId]);
        }

        return null;
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

        } catch (Exception $e) {
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

    /**
     * The newest PropertyWare work order for a building, straight from SOAP.
     * Used to learn how PropertyWare itself refers to the building — its piped
     * "PORTFOLIO | BUILDING" location string and its unit ID — before creating
     * a new work order there (see createWorkOrder).
     *
     * Returns the newest work order that actually carries a location rather
     * than the newest outright: a work order raised without one comes back with
     * location empty, and taking it blindly leaves createWorkOrder nothing to
     * validate against, so the create is refused even though the building has
     * plenty of usable history. Observed on 123 Demo St., whose two newest work
     * orders are blank while the eight behind them all read "DEMO | 123DEMOST.".
     * Falls back to the newest work order so the unit ID is still available.
     *
     * The WSDL's getWorkOrders request type requires pageNumber; omitting it
     * fails encoding ("object has no 'pageNumber' property") before the
     * request is even sent.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestWorkOrderForBuilding($buildingId): ?array
    {
        try {
            $client = $this->initiate();

            $response = $client->getWorkOrders([
                'buildingId' => (int) $buildingId,
                'orderByNewestFirst' => true,
                'pageNumber' => 1,
            ]);

            $workOrders = json_decode(json_encode($response), true);

            if (! is_array($workOrders)) {
                return null;
            }

            foreach ($workOrders as $workOrder) {
                if (is_array($workOrder) && filled($workOrder['location'] ?? null)) {
                    return $workOrder;
                }
            }

            return $workOrders[0] ?? null;
        } catch (Exception $e) {
            Log::error('Fetching the latest PropertyWare work order for a building failed: '.$e->getMessage(), [
                'building_id' => $buildingId,
            ]);

            return null;
        }
    }

    /**
     * Create a brand-new work order in PropertyWare (SOAP). Everything else in
     * this service assumes work orders originate in PropertyWare, so HOA intake
     * creates there first and imports the row back. Returns the new
     * PropertyWare work order ID, or null when the create fails (callers fall
     * back to a local-only work order).
     *
     * PropertyWare validates the create against its "Location" — the unit — and
     * rejects the whole call with "Location is invalid" unless BOTH the piped
     * "PORTFOLIO | BUILDING" location string and the unit ID match what it has
     * on record. Neither is optional: omitting them is why every HOA create
     * silently failed until 2026-08-04. Callers should copy both off an
     * existing work order for the building (getLatestWorkOrderForBuilding)
     * rather than reconstruct them.
     *
     * Sent through the WSDL-aware SoapClient rather than hand-built XML so the
     * encoding (notably the unitIDs array) is always what the service expects.
     * The scalar zero/false fields are required by the schema; PropertyWare
     * assigns the real ID and number.
     *
     * @param  array{building_id: int|string, portfolio_id: int|string, category: string, description: string, type?: string, unit_id?: int|string|null, location?: ?string}  $data
     */
    public function createWorkOrder(array $data): ?string
    {
        if (blank($data['unit_id'] ?? null) || blank($data['location'] ?? null)) {
            Log::error('Refusing to create a PropertyWare work order without its unit and location (PropertyWare rejects the create as "Location is invalid").', [
                'building_id' => $data['building_id'] ?? null,
                'portfolio_id' => $data['portfolio_id'] ?? null,
            ]);

            return null;
        }

        try {
            $client = $this->initiate();

            $created = $client->createWorkOrder([
                'ID' => 0,
                'approved' => false,
                'costEstimate' => 0.0,
                'hourEstimate' => 0.0,
                'number' => 0,
                'priorityAsInt' => 2,
                'totalHourWorked' => 0.0,
                'portfolio' => [
                    'ID' => (int) $data['portfolio_id'],
                    'active' => true,
                    'managementFlatFee' => 0.0,
                    'targetOperatingReserve' => 0.0,
                ],
                'unitIDs' => [(int) $data['unit_id']],
                'location' => (string) $data['location'],
                'category' => (string) ($data['category'] ?? ''),
                'type' => (string) ($data['type'] ?? ''),
                'status' => 'Open',
                'description' => (string) ($data['description'] ?? ''),
            ]);

            $propertywareId = is_object($created) && ! empty($created->ID) ? (string) $created->ID : null;

            if ($propertywareId !== null) {
                Log::info('Work order created in PropertyWare', [
                    'propertyware_id' => $propertywareId,
                    'building_id' => $data['building_id'] ?? null,
                ]);

                return $propertywareId;
            }

            Log::error('PropertyWare createWorkOrder returned no ID.', [
                'building_id' => $data['building_id'] ?? null,
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::error('Failed to create work order in PropertyWare', [
                'building_id' => $data['building_id'] ?? null,
                'portfolio_id' => $data['portfolio_id'] ?? null,
                'category' => $data['category'] ?? null,
                'error' => $exception instanceof \SoapFault ? 'SOAP_FAULT' : get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function updateWorkOrder($workOrder, array $changes = [])
    {
        // PropertyWare's updateWorkOrder endpoint uses JSON Merge Patch, so we send ONLY
        // the fields we intend to change (see buildWorkOrderPatchPayload). PropertyWare
        // rejects edits to work orders it considers closed with a 400 ("...already
        // closed") and can also return a 500; we return that outcome to the caller so it
        // can be surfaced to the user instead of failing silently.
        $payload = $this->buildWorkOrderPatchPayload($workOrder, $changes);

        $response = Http::withHeaders($this->headers)->patch('https://api.propertyware.com/pw/api/rest/v1/workorders/'.$workOrder->propertyware_id, $payload);

        Log::info('PropertyWare updateWorkOrder patch', [
            'work_order_no' => $workOrder->work_order_no,
            'propertyware_id' => $workOrder->propertyware_id,
            'payload_sent' => $payload,
            'status_code' => $response->status(),
            'response_body' => $response->body(),
        ]);

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

        $ok = $response->successful() && $res->successful();

        if ($ok) {
            Log::info('Success in updating work order', [
                'work order' => $workOrder->work_order_no,
                'status_code' => $res->status(),
            ]);
        } else {
            // Log BOTH responses separately so the actually-failing call is visible.
            // (Previously the error reported the main update's response, which masked
            // a failing custom-fields call behind a 200 + work order body.)
            Log::error('Error updating Work Order', [
                'work_order' => $workOrder->work_order_no,
                'work_order_update' => [
                    'status_code' => $response->status(),
                    'body' => $response->body(),
                ],
                'custom_fields_update' => [
                    'status_code' => $res->status(),
                    'body' => $res->body(),
                ],
            ]);
        }

        // Surface PropertyWare's own message (e.g. "Can not edit work order. It is
        // already closed") from whichever call failed.
        $failed = ! $response->successful() ? $response : (! $res->successful() ? $res : null);

        return [
            'ok' => $ok,
            'status' => $response->status(),
            'message' => $ok
                ? 'Synced to PropertyWare.'
                : ($failed?->json('userMessage') ?: 'PropertyWare rejected the update (HTTP '.($failed?->status() ?? 0).').'),
        ];
    }

    /**
     * Build a JSON Merge Patch body for PropertyWare's updateWorkOrder endpoint.
     *
     * Only the standard work order fields our UI can edit are mapped here; custom
     * fields (Zone, Management Plan, Additional work needed) are synced separately via
     * the customfields endpoint. Empty values are omitted so we never send an invalid
     * enum/date or clobber existing PropertyWare data.
     *
     * @param  array<string, mixed>  $changes  The fields that were updated locally.
     * @return array<string, mixed>
     */
    private function buildWorkOrderPatchPayload($workOrder, array $changes): array
    {
        // Local column => PropertyWare PATCH field.
        $map = [
            'category' => 'category',
            'type' => 'type',
            'description' => 'description',
        ];

        // Patch only what changed when we know it; otherwise fall back to the
        // editable standard fields currently on the model.
        $source = ! empty($changes) ? $changes : $workOrder->only(array_keys($map));

        $payload = [];
        foreach ($map as $local => $pwField) {
            if (! array_key_exists($local, $source)) {
                continue;
            }

            $value = $source[$local];

            if ($value === null || $value === '') {
                continue;
            }

            // PropertyWare matches picklist values verbatim (its real HVAC
            // value is "HVAC " with a trailing space), so always send the
            // exact spelling from the categories table.
            if ($local === 'category') {
                $value = WorkOrderCategory::canonicalName($value);
            }

            $payload[$pwField] = $value;
        }

        return $payload;
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

        } catch (Exception $exception) {
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

        } catch (Exception $exception) {
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

        } catch (Exception $exception) {
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

            throw new Exception('PropertyWare API Error: '.$res['message']);
        }

        if ($workOrder->is_approved) {
            $this->approvedWorkOrder($workOrder);
        }

        // When THMP is among the assigned vendors, create the matching Jobber
        // job and store its link on the work order. Queued + gated + idempotent,
        // so this is safe to fire on every vendor change. Replaces the previous
        // POST to the n8n "create-job" workflow (now owned in-app).
        if (Vendor::isThmpAssignedToWorkOrder($workOrder->id)) {
            CreateJobberJobForWorkOrder::dispatch($workOrder->id);
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
                throw new Exception('File does not exist: '.$absolutePath);
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

                // Return the exact name stored in PropertyWare so the caller can
                // record it and later skip re-pulling this upload as a document.
                return $fileName;
            }

            Log::error('Error uploading work order attachment', [
                'status' => $response->status(),
                'body' => $response->body(),
                'error' => $response->json(),
                'fileName' => $fileName,
            ]);

            return false;

        } catch (Exception $e) {
            Log::error('Error uploading work order attachment: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Upload raw PDF bytes as a document on a PropertyWare work order. Mirrors
     * uploadVendorAttachment but takes in-memory content instead of a file on
     * disk, for documents we generate ourselves (e.g. the Work Order
     * Information sheet).
     *
     * @return string|false the created PropertyWare document id on success, false on failure
     */
    public function uploadWorkOrderPdf(?string $propertywareWorkOrderId, string $contents, string $fileName, string $description): string|false
    {
        if (! $propertywareWorkOrderId) {
            return false;
        }

        try {
            $response = Http::withHeaders($this->headers)
                ->attach('file', $contents, $fileName)
                ->post('https://api.propertyware.com/pw/api/rest/v1/docs', [
                    'entityId' => $propertywareWorkOrderId,
                    'entityType' => 'Work Order',
                ]);

            if (! $response->successful()) {
                Log::error('Error uploading work order PDF', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'fileName' => $fileName,
                ]);

                return false;
            }

            $docId = $response->json('id');

            $putResponse = Http::withHeaders($this->headers)
                ->put('https://api.propertyware.com/pw/api/rest/v1/docs/'.$docId, [
                    'fileName' => $fileName,
                    'description' => $description,
                    'publishToOwnerPortal' => 'false',
                    'publishToTenantPortal' => 'false',
                ]);

            if (! $putResponse->successful()) {
                Log::error('Failed to update work order PDF metadata', [
                    'doc_id' => $docId,
                    'status' => $putResponse->status(),
                    'body' => $putResponse->body(),
                ]);

                return false;
            }

            Log::info('Work order information PDF uploaded to PropertyWare', [
                'entity_id' => $propertywareWorkOrderId,
                'file_name' => $fileName,
                'doc_id' => $docId,
            ]);

            // Return the PropertyWare document id so the caller can record a
            // local WorkOrderDocuments row that streams this file back on demand.
            return $docId ? (string) $docId : false;
        } catch (Exception $e) {
            Log::error('Error uploading work order PDF: '.$e->getMessage());

            return false;
        }
    }

    public function uploadVendorInvoice($workOrderId, $invoice)
    {
        try {
            $workOrder = WorkOrder::find($workOrderId);
            if (! $workOrder) {
                throw new Exception("Work order not found: $workOrderId");
            }

            $absolutePath = public_path('storage/invoices/'.basename($invoice->filename));
            if (! file_exists($absolutePath)) {
                throw new Exception("File does not exist: $absolutePath");
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

        } catch (Exception $e) {
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
                throw new Exception('Work order not found.');
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
        } catch (Exception $e) {
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
                throw new Exception('Work order not found.');
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
        } catch (Exception $e) {
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
                throw new Exception('Work order not found.');
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
        } catch (Exception $e) {
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
        if (! $workOrder->is_approved) {
            return;
        }

        $client = $this->initiate();

        $work_order_no = $workOrder->work_order_no;
        $approved = $workOrder->is_approved;
        $approvedDate = $workOrder->approved_date ?? '';
        $approvalComment = $workOrder->approval_comments ?? '';

        $client->approveWorkOrder($work_order_no, $approved, $approvedDate, $approvalComment);

        Log::info('Work order approval has been added!', [
            'Work order no' => $work_order_no,
        ]);
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
