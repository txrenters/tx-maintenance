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
    protected $signature = 'import:all-work-orders {--limit= : Stop after importing this many work orders (for local testing)}';

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

        $maxToImport = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $batchSize = $maxToImport !== null ? min(500, $maxToImport) : 500;
        $offset = 0;
        $totalProcessed = 0;

        Log::info('Work Orders import started.', ['limit' => $maxToImport]);

        try {
            while (true) {
                $workOrders = $this->fetchBatch($headers, $batchSize, $offset);

                if ($workOrders === null) {
                    // All retries exhausted. Abort rather than advance the
                    // offset: with PropertyWare down every page returns null
                    // and the "skip this batch" loop never terminates.
                    $this->error("PropertyWare could not be fetched at offset {$offset}; aborting this run.");

                    return Command::FAILURE;
                }

                if (empty($workOrders)) {
                    break;
                }

                if ($maxToImport !== null && $totalProcessed + count($workOrders) > $maxToImport) {
                    $workOrders = array_slice($workOrders, 0, $maxToImport - $totalProcessed);
                }

                Log::info('Fetched work orders batch', [
                    'offset' => $offset,
                    'count' => count($workOrders),
                ]);

                $this->processBatch($workOrders, $headers);

                $totalProcessed += count($workOrders);
                $offset += $batchSize;

                if ($maxToImport !== null && $totalProcessed >= $maxToImport) {
                    break;
                }

                // Fewer results than the batch size means we've reached the last page
                if (count($workOrders) < $batchSize) {
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
                    $this->recordVendorAssignment($workOrder->id, $vendorId);
                }
            }
        }

        return $workOrder;
    }

    /**
     * Record that a vendor is assigned to a work order, leaving created_at
     * alone once the row exists — the assignment date the tenant
     * vendor-contact follow-up ages off must survive a re-import.
     */
    private function recordVendorAssignment(int $workOrderId, int $vendorId): void
    {
        $touched = DB::table('work_order_vendors')
            ->where('work_order_id', $workOrderId)
            ->where('vendor_id', $vendorId)
            ->update(['updated_at' => now()]);

        if ($touched === 0) {
            DB::table('work_order_vendors')->insert([
                'work_order_id' => $workOrderId,
                'vendor_id' => $vendorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function findOrCreateBuilding(int $propertywareId, array $headers): int
    {
        if (isset($this->buildingCache[$propertywareId])) {
            return $this->buildingCache[$propertywareId];
        }

        $existing = Building::where('propertyware_id', $propertywareId)->first();

        if ($existing && $existing->details_synced_at !== null) {
            return $this->buildingCache[$propertywareId] = $existing->propertyware_id;
        }

        $response = Http::withHeaders($headers)
            ->get("https://api.propertyware.com/pw/api/rest/v1/buildings/{$propertywareId}", [
                'includeCustomFields' => 'true',
            ]);

        if (! $response->successful()) {
            Log::warning('Could not fetch building from PropertyWare', ['propertyware_id' => $propertywareId]);

            return $this->buildingCache[$propertywareId] = $propertywareId;
        }

        $data = $response->json();

        Building::updateOrCreate(
            ['propertyware_id' => $propertywareId],
            $this->mapBuildingPayload($data),
        );

        return $this->buildingCache[$propertywareId] = $propertywareId;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapBuildingPayload(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'address' => $data['address']['address'] ?? null,
            'address_cont' => $data['address']['addressCont'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'state_region' => $data['address']['stateRegion'] ?? null,
            'postal_code' => $data['address']['postalCode'] ?? null,
            'country' => $data['address']['country'] ?? null,
            'portfolio_id' => $data['portfolioID'] ?? null,
            'active' => $data['active'] ?? true,
            'maintenance_notice' => $data['maintenanceNotice'] ?? null,
            'maintenance_spending_limit_amount' => $data['maintenanceSpendingLimitAmount'] ?? null,
            'maintenance_spending_limit_time' => $data['maintenanceSpendingLimitTime'] ?? null,
            'maintenance_labor_surcharge_amount' => $data['maintenanceLaborSurchargeAmount'] ?? null,
            'maintenance_labor_surcharge_type' => $data['maintenanceLaborSurchargeType'] ?? null,
            'category' => $data['category'] ?? null,
            'property_type' => $data['propertyType'] ?? null,
            'custom_fields' => $data['customFields'] ?? null,
            'details_synced_at' => now(),
        ];
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
