<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateWorkOrderStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:work-orders-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve work orders from PropertyWare API and update work order details';

    protected PropertyWareService $propertyWareService;

    /** @var array<int, int> Cache of propertyware_id => local building propertyware_id */
    private array $buildingCache = [];
    /** @var array<int, int> Cache of propertyware_id => local vendor id */
    private array $vendorCache = [];

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Log::info('Work Orders updates are running.');

        $retrievedCount = 0;
        $updatedCount = 0;

        try {
            $this->propertyWareService->streamWorkOrdersViaRestAPI(function (array $workOrders) use (&$retrievedCount, &$updatedCount) {
                $retrievedCount += count($workOrders);
                $existingWorkOrderMap = $this->getExistingWorkOrderIdMap($workOrders);

                foreach ($workOrders as $order) {
                    $data = (array) $order;
                    $propertywareId = isset($data['id']) ? (int) $data['id'] : null;

                    if (! $propertywareId) {
                        continue;
                    }

                    $workOrderId = $existingWorkOrderMap[$propertywareId] ?? null;
                    if (! $workOrderId) {
                        continue;
                    }

                    $workOrderData = $this->buildWorkOrderUpdatePayload($data);
                    if (! empty($workOrderData)) {
                        $workOrderData['updated_at'] = now();
                        DB::table('work_orders')
                            ->where('id', $workOrderId)
                            ->update($workOrderData);
                        $updatedCount++;
                    }

                    if (array_key_exists('assignedVendors', $data)) {
                        $this->syncAssignedVendors($workOrderId, $data['assignedVendors']);
                    }
                }

                unset($existingWorkOrderMap, $workOrders);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            });

            if ($retrievedCount === 0) {
                Log::warning('No work orders returned from Propertyware API.');

                return;
            }

            Log::info('Successfully updated Work order details!', [
                'retrieved_count' => $retrievedCount,
                'updated_count' => $updatedCount,
            ]);
        } catch (\Throwable $th) {
            Log::error('Updating Work order failed: '.$th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);
        }

    }

    /**
     * @param  array<int, mixed>  $workOrders
     * @return array<int, int>
     */
    private function getExistingWorkOrderIdMap(array $workOrders): array
    {
        $propertywareIds = [];

        foreach ($workOrders as $order) {
            $data = (array) $order;
            if (! empty($data['id'])) {
                $propertywareIds[] = (int) $data['id'];
            }
        }

        $propertywareIds = array_values(array_unique($propertywareIds));
        if (empty($propertywareIds)) {
            return [];
        }

        return DB::table('work_orders')
            ->whereIn('propertyware_id', $propertywareIds)
            ->pluck('id', 'propertyware_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildWorkOrderUpdatePayload(array $data): array
    {
        $workOrderData = [];

        if (array_key_exists('status', $data)) {
            $workOrderData['status'] = $data['status'];
        }
        if (array_key_exists('authorizedToEnter', $data)) {
            $workOrderData['authorized_to_enter'] = $data['authorizedToEnter'];
        }
        if (array_key_exists('category', $data)) {
            $workOrderData['category'] = $data['category'];
        }
        if (array_key_exists('completedDate', $data)) {
            $workOrderData['completed_date'] = $this->safeParseDate($data['completedDate'], true);
        }
        if (array_key_exists('costEstimate', $data)) {
            $workOrderData['cost_estimate'] = $data['costEstimate'];
        }
        if (array_key_exists('description', $data)) {
            $workOrderData['description'] = $data['description'];
        }
        if (array_key_exists('hourEstimate', $data)) {
            $workOrderData['hour_estimate'] = $data['hourEstimate'];
        }
        if (array_key_exists('priority', $data)) {
            $workOrderData['priority'] = $data['priority'];
        }
        if (array_key_exists('requiredMaterials', $data)) {
            $workOrderData['required_materials'] = $data['requiredMaterials'];
        }
        if (array_key_exists('source', $data)) {
            $workOrderData['source'] = $data['source'];
        }
        if (array_key_exists('specificLocation', $data)) {
            $workOrderData['specific_location'] = $data['specificLocation'];
        }
        if (array_key_exists('type', $data)) {
            $workOrderData['type'] = $data['type'];
        }
        if (array_key_exists('actualCost', $data)) {
            $workOrderData['total_cost'] = $data['actualCost'];
        }
        if (array_key_exists('scheduledEndDate', $data)) {
            $workOrderData['scheduled_end_date'] = $this->safeParseDate($data['scheduledEndDate'], true);
        }
        if (array_key_exists('approved', $data)) {
            $workOrderData['is_approved'] = (bool) $data['approved'];
        }
        if (! empty($data['buildingID'])) {
            $workOrderData['building_id'] = $this->findOrCreateBuilding((int) $data['buildingID']);
        }

        if (isset($data['customFields']) && is_array($data['customFields'])) {
            foreach ($data['customFields'] as $customField) {
                if (($customField['fieldName'] ?? null) == 'Service Status') {
                    $serviceStatusId = DB::table('service_status')
                        ->whereLike('name', '%'.($customField['value'] ?? '').'%')
                        ->value('id');

                    $workOrderData['service_status_id'] = $serviceStatusId ?? 1;

                } elseif (($customField['fieldName'] ?? null) == 'Zone') {
                    $workOrderData['zone'] = $customField['value'] ?? '';
                } elseif (($customField['fieldName'] ?? null) == 'Additional work needed- Reschedule') {
                    $workOrderData['additional_work_needed_reschedule'] = $customField['value'] ?? '';
                } elseif (($customField['fieldName'] ?? null) == 'Management Plan') {
                    $workOrderData['management_plan'] = $customField['value'] ?? '';
                } elseif (($customField['fieldName'] ?? null) == 'closing comment') {
                    $workOrderData['closing_comments'] = $customField['value'] ?? '';
                }
            }
        }

        return $workOrderData;
    }

    private function safeParseDate(?string $value, bool $dateOnly = false): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $carbon = Carbon::parse($value);

            return $dateOnly ? $carbon->toDateString() : $carbon->toDateTimeString();
        } catch (\Exception) {
            try {
                $carbon = Carbon::createFromFormat('Y-m-d\TH:i A', $value);

                return $dateOnly ? $carbon->toDateString() : $carbon->toDateTimeString();
            } catch (\Exception) {
                Log::warning('Could not parse date from PropertyWare', ['value' => $value]);

                return null;
            }
        }
    }

    /**
     * @param  mixed  $assignedVendors
     */
    private function syncAssignedVendors(int $workOrderId, $assignedVendors): void
    {
        if (! is_array($assignedVendors)) {
            return;
        }

        $incomingVendorIds = [];

        foreach ($assignedVendors as $vendor) {
            if (! is_array($vendor)) {
                continue;
            }

            $vendorId = $this->findOrCreateVendorId($vendor);
            if (! $vendorId) {
                continue;
            }

            $incomingVendorIds[] = $vendorId;

            DB::table('work_order_vendors')->updateOrInsert(
                ['vendor_id' => $vendorId, 'work_order_id' => $workOrderId],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $pivotQuery = DB::table('work_order_vendors')->where('work_order_id', $workOrderId);
        if (empty($incomingVendorIds)) {
            $pivotQuery->delete();

            return;
        }

        $pivotQuery->whereNotIn('vendor_id', array_values(array_unique($incomingVendorIds)))->delete();
    }

    /**
     * @param  array<string, mixed>  $vendorData
     */
    private function findOrCreateVendorId(array $vendorData): ?int
    {
        $propertywareId = isset($vendorData['id']) ? (int) $vendorData['id'] : null;
        if (! $propertywareId) {
            return null;
        }

        if (isset($this->vendorCache[$propertywareId])) {
            return $this->vendorCache[$propertywareId];
        }

        $vendorId = DB::table('vendors')
            ->where('propertyware_id', $propertywareId)
            ->value('id');

        if (! $vendorId) {
            $vendorId = $this->createVendor($vendorData);
        }

        if (! $vendorId) {
            return null;
        }

        return $this->vendorCache[$propertywareId] = (int) $vendorId;
    }

    private function findOrCreateBuilding(int $propertywareId): int
    {
        if (isset($this->buildingCache[$propertywareId])) {
            return $this->buildingCache[$propertywareId];
        }

        $existingPropertywareId = DB::table('buildings')
            ->where('propertyware_id', $propertywareId)
            ->value('propertyware_id');

        if ($existingPropertywareId) {
            return $this->buildingCache[$propertywareId] = (int) $existingPropertywareId;
        }

        $headers = [
            'x-propertyware-client-id' => config('services.propertyware.client_id'),
            'x-propertyware-client-secret' => config('services.propertyware.client_secret_key'),
            'x-propertyware-system-id' => config('services.propertyware.system_id'),
        ];

        $response = Http::withHeaders($headers)
            ->get("https://api.propertyware.com/pw/api/rest/v1/buildings/{$propertywareId}");

        if (! $response->successful()) {
            Log::warning('Could not fetch building from PropertyWare', ['propertyware_id' => $propertywareId]);

            return $propertywareId;
        }

        $data = $response->json();

        DB::table('buildings')->updateOrInsert(
            ['propertyware_id' => $propertywareId],
            [
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
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $this->buildingCache[$propertywareId] = $propertywareId;
    }

    private function createVendor(array $vendorData): ?int
    {
        $address = trim(implode(' ', array_filter([
            $vendorData['address'] ?? null,
            $vendorData['address2'] ?? null,
            $vendorData['city'] ?? null,
            $vendorData['state'] ?? null,
            $vendorData['country'] ?? null,
            $vendorData['zip'] ?? null,
        ])));

        $vendorEmail = $vendorData['email'] ?? $vendorData['id'].'@texasrenter.com';

        $usersData = [
            'email' => $vendorEmail,
            'name' => $vendorData['name'],
            'phone' => $vendorData['phone'] ?? null,
            'company' => $vendorData['companyName'] ?? null,
            'address' => $address,
            'password' => bcrypt($vendorEmail),
        ];

        $user = $this->createOrUpdateUser($usersData, 'vendor');

        $vendorsData = [
            'propertyware_id' => $vendorData['id'],
            'name' => $vendorData['name'],
            'name_on_check' => $vendorData['name'],
            'email' => $vendorEmail,
            'user_id' => $user->id,
            'is_active' => $vendorData['active'] ?? true,
        ];

        $vendor = Vendor::updateOrCreate(
            ['propertyware_id' => $vendorData['id']],
            $vendorsData
        );

        return $vendor->id;
    }

    private function processNotes(array $data, int $work_order): void
    {
        $now = now();

        $notesData = [];
        if (! empty($data['notes']) && is_array($data['notes'])) {
            foreach ($data['notes'] as $note) {
                $notesData[] = [
                    'propertyware_id' => $note['id'] ?? null,
                    'client_data' => $note['clientData'] ?? null,
                    'subject' => $note['subject'] ?? null,
                    'body' => $note['body'] ?? null,
                    'is_private' => $note['private'] ?? null,
                    'date' => $note['date'] ?? '',
                    'is_default' => $note['default'] ?? false,
                    'work_order_id' => $work_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('work_order_notes')->where('work_order_id', $work_order)->delete();
        DB::table('work_order_notes')->insert($notesData);
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);

        return $user;
    }
}
