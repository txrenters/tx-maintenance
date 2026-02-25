<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportAllWorkOrdersCommand extends Command
{
    protected $signature = 'import:all-work-orders';

    protected $description = 'Import and update all work orders from PropertyWare';

    /** @var array<int, int> Cache of propertyware_id => local building id */
    private array $buildingCache = [];

    public function handle(): int
    {
        $headers = [
            'x-propertyware-client-id' => config('services.propertyware.client_id'),
            'x-propertyware-client-secret' => config('services.propertyware.client_secret_key'),
            'x-propertyware-system-id' => config('services.propertyware.system_id'),
        ];

        // PropertyWare API max per request
        $limit = 500;
        $offset = 0;
        $totalProcessed = 0;

        Log::info('Work Orders import started.');

        try {
            while (true) {
                $workOrders = $this->fetchBatch($headers, $limit, $offset);

                if ($workOrders === null) {
                    // All retries exhausted for this batch — skip and continue
                    $offset += $limit;

                    continue;
                }

                if (empty($workOrders)) {
                    break;
                }

                Log::info('Fetched work orders batch', [
                    'offset' => $offset,
                    'count' => count($workOrders),
                ]);

                $this->processBatch($workOrders, $headers);

                $totalProcessed += count($workOrders);
                $offset += $limit;

                // Fewer results than the limit means we've reached the last page
                if (count($workOrders) < $limit) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Work order import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        Log::info('Successfully imported Work Orders. Total: '.$totalProcessed);

        return Command::SUCCESS;
    }

    /**
     * Fetch a single page of work orders from PropertyWare, retrying up to 3 times on server errors.
     * Returns null if all attempts fail so the caller can skip the batch.
     *
     * @param  array<string, string>  $headers
     * @return array<int, mixed>|null
     */
    private function safeParseDate(?string $value, bool $dateOnly = false): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $carbon = Carbon::parse($value);

            return $dateOnly ? $carbon->toDateString() : $carbon->toDateTimeString();
        } catch (\Exception) {
            // Fall back to PropertyWare's non-standard format: 2026-02-22T01:00 AM
            try {
                $carbon = Carbon::createFromFormat('Y-m-d\TH:i A', $value);

                return $dateOnly ? $carbon->toDateString() : $carbon->toDateTimeString();
            } catch (\Exception $e) {
                Log::warning('Could not parse date from PropertyWare', ['value' => $value]);

                return null;
            }
        }
    }

    private function fetchBatch(array $headers, int $limit, int $offset): ?array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::withHeaders($headers)->get('https://api.propertyware.com/pw/api/rest/v1/workorders', [
                'includeCustomFields' => 'true',
                'orderby' => 'createddate DESC',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->serverError() && $attempt < $maxRetries) {
                Log::warning('PropertyWare 5xx error, retrying batch', [
                    'offset' => $offset,
                    'status_code' => $response->status(),
                    'attempt' => $attempt,
                ]);
                sleep(5);

                continue;
            }

            Log::error('Error retrieving Work Orders', [
                'status_code' => $response->status(),
                'body' => $response->body(),
                'offset' => $offset,
                'attempt' => $attempt,
            ]);

            return null;
        }

        return null;
    }

    private function processBatch(array $workOrders, array $headers): void
    {
        // Pre-fetch all matching work orders in one query to avoid N+1
        $propertywareIds = array_column($workOrders, 'id');
        $existingWorkOrders = WorkOrder::whereIn('propertyware_id', $propertywareIds)
            ->get()
            ->keyBy('propertyware_id');

        foreach ($workOrders as $order) {
            $data = (array) $order;
            $pwId = $data['id'] ?? null;

            if (! $pwId) {
                continue;
            }

            // Skip existing work orders — updates are handled by a separate command
            if ($existingWorkOrders->has($pwId)) {
                continue;
            }

            $this->createWorkOrder($data, $headers);
        }
    }

    private function createWorkOrder(array $data, array $headers): WorkOrder
    {
        $woc = User::role('woc')->first();

        $workOrderData = [
            'propertyware_id' => $data['id'],
            'client_data' => $data['clientData'] ?? null,
            'work_order_no' => $data['number'] ?? null,
            'is_approved' => $data['approved'] ?? false,
            'approved_date' => $this->safeParseDate($data['approvedDate'] ?? null, dateOnly: true),
            'authorized_to_enter' => $data['authorizedToEnter'] ?? null,
            'category' => $data['category'] ?? null,
            'completed_date' => $this->safeParseDate($data['completedDate'] ?? null, dateOnly: true),
            'cost_estimate' => $data['costEstimate'] ?? null,
            'created_date' => $this->safeParseDate($data['createdDateTime'] ?? null),
            'date_to_enter' => $this->safeParseDate($data['dateToEnter'] ?? null, dateOnly: true),
            'description' => $data['description'] ?? null,
            'hour_estimate' => $data['hourEstimate'] ?? null,
            'location' => $data['location'] ?? null,
            'priority' => $data['priority'] ?? null,
            'priority_as_int' => 0,
            'required_materials' => $data['requiredMaterials'] ?? null,
            'scheduled_end_date' => $this->safeParseDate($data['scheduledEndDate'] ?? null, dateOnly: true),
            'source' => $data['source'] ?? null,
            'specific_location' => $data['specificLocation'] ?? null,
            'start_date' => $this->safeParseDate($data['startDate'] ?? null),
            'status' => $data['status'] ?? null,
            'total_cost' => $data['actualCost'] ?? null,
            'type' => $data['type'] ?? null,
            'building_id' => isset($data['buildingID']) ? $this->findOrCreateBuilding($data['buildingID'], $headers) : null,
            'portfolio_id' => $data['portfolioID'] ?? null,
            'unit_id' => $data['unitID'] ?? null,
            'user_id' => $woc?->id,
        ];

        if (isset($data['customFields']) && is_array($data['customFields'])) {
            foreach ($data['customFields'] as $customField) {
                if ($customField['fieldName'] === 'Service Status') {
                    $serviceStatusId = DB::table('service_status')
                        ->whereLike('name', '%'.($customField['value'] ?? '').'%')
                        ->value('id');
                    $workOrderData['service_status_id'] = $serviceStatusId ?? 1;
                } elseif ($customField['fieldName'] === 'Zone') {
                    $workOrderData['zone'] = $customField['value'] ?? '';
                } elseif ($customField['fieldName'] === 'Additional work needed- Reschedule') {
                    $workOrderData['additional_work_needed_reschedule'] = $customField['value'] ?? '';
                } elseif ($customField['fieldName'] === 'Management Plan') {
                    $workOrderData['management_plan'] = $customField['value'] ?? '';
                } elseif ($customField['fieldName'] === 'closing comment') {
                    $workOrderData['closing_comments'] = $customField['value'] ?? '';
                }
            }
        }

        $workOrder = WorkOrder::create($workOrderData);

        if (! empty($data['assignedVendors'])) {
            foreach ($data['assignedVendors'] as $vendor) {
                $vendorId = DB::table('vendors')
                    ->where('propertyware_id', $vendor['id'])
                    ->value('id');

                if (! $vendorId) {
                    $vendorId = $this->createVendor($vendor);
                }

                if ($vendorId) {
                    DB::table('work_order_vendors')->updateOrInsert(
                        ['vendor_id' => $vendorId, 'work_order_id' => $workOrder->id],
                        ['created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }

        return $workOrder;
    }

    private function findOrCreateBuilding(int $propertywareId, array $headers): int
    {
        if (isset($this->buildingCache[$propertywareId])) {
            return $this->buildingCache[$propertywareId];
        }

        $existing = Building::where('propertyware_id', $propertywareId)->first();

        if ($existing) {
            return $this->buildingCache[$propertywareId] = $existing->propertyware_id;
        }

        $response = Http::withHeaders($headers)
            ->get("https://api.propertyware.com/pw/api/rest/v1/buildings/{$propertywareId}");

        if (! $response->successful()) {
            Log::warning('Could not fetch building from PropertyWare', ['propertyware_id' => $propertywareId]);

            return $propertywareId;
        }

        $data = $response->json();

        Building::create([
            'propertyware_id' => $propertywareId,
            'name' => $data['name'] ?? null,
            'address' => $data['address']['address'] ?? null,
            'address_cont' => $data['address']['addressCont'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'state_region' => $data['address']['stateRegion'] ?? null,
            'postal_code' => $data['address']['postalCode'] ?? null,
            'country' => $data['address']['country'] ?? null,
            'portfolio_id' => $data['portfolioID'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return $this->buildingCache[$propertywareId] = $propertywareId;
    }

    private function createVendor(array $vendorData): ?int
    {
        $vendorEmail = $vendorData['email'] ?? $vendorData['id'].'@texasrenter.com';

        $user = $this->createOrUpdateUser([
            'email' => $vendorEmail,
            'name' => $vendorData['name'],
            'phone' => $vendorData['phone'] ?? null,
            'address' => $vendorData['address'] ?? null,
            'password' => bcrypt($vendorEmail),
        ], 'vendor');

        $vendor = Vendor::create([
            'propertyware_id' => $vendorData['id'],
            'name' => $vendorData['name'],
            'name_on_check' => $vendorData['name'],
            'email' => $vendorEmail,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return $vendor->id;
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);

        return $user;
    }
}
