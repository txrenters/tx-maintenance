<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

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
        $processedCount = 0;

        try {
            $workOrders = $this->propertyWareService->getWorkOrdersViaRestAPIWithTotalCap(5000, 500);
            if (! is_array($workOrders)) {
                Log::error('Unable to retrieve work orders via REST API.', ['response' => $workOrders]);

                return;
            }

            foreach (array_chunk($workOrders, 100) as $workOrderChunk) {
                foreach ($workOrderChunk as $order) {
                    $data = (array) $order;

                    $ID = $data['id'] ?? null;
                    if (! $ID) {
                        continue;
                    }

                    $workOrder = WorkOrder::where('propertyware_id', $data['id'])->first();

                    if (! $workOrder) {
                        continue;
                    }

                    $work_order_data = [
                        'status' => $data['status'] ?? $workOrder->status,
                        'authorized_to_enter' => $data['authorizedToEnter'] ?? $workOrder->authorized_to_enter,
                        'category' => $data['category'] ?? $workOrder->category,
                        'completed_date' => $data['completedDate'] ?? $workOrder->completed_date,
                        'cost_estimate' => $data['costEstimate'] ?? $workOrder->cost_estimate,
                        'description' => $data['description'] ?? $workOrder->description,
                        'hour_estimate' => $data['hourEstimate'] ?? $workOrder->hour_estimate,
                        'priority' => $data['priority'] ?? $workOrder->priority,
                        'required_materials' => $data['requiredMaterials'] ?? $workOrder->required_materials,
                        'source' => $data['source'] ?? $workOrder->source,
                        'specific_location' => $data['specificLocation'] ?? $workOrder->specific_location,
                        'type' => $data['type'] ?? $workOrder->type,
                        'total_cost' => $data['actualCost'] ?? $workOrder->total_cost,
                        'scheduled_end_date' => ! empty($data['scheduledEndDate']) ? Carbon::parse($data['scheduledEndDate'])->toDateString() : null,
                        'is_approved' => $data['approved'] ?? $workOrder->is_approved,
                        'building_id' => isset($data['buildingID']) ? $this->findOrCreateBuilding($data['buildingID']) : $workOrder->building_id,
                    ];

                    $customFieldData = [];

                    if (isset($data['customFields']) && is_array($data['customFields'])) {
                        foreach ($data['customFields'] as $customField) {
                            if ($customField['fieldName'] == 'Service Status') {

                                $service_status_id = DB::table('service_status')
                                    ->whereLike('name', '%'.($customField['value'] ?? '').'%')
                                    ->value('id');

                                $work_order_data['service_status_id'] = $service_status_id ?? 1;

                            } elseif ($customField['fieldName'] == 'Zone') {
                                $work_order_data['zone'] = $customField['value'] ?? '';
                            } elseif ($customField['fieldName'] == 'Additional work needed- Reschedule') {
                                $work_order_data['additional_work_needed_reschedule'] = $customField['value'] ?? '';
                            } elseif ($customField['fieldName'] == 'Management Plan') {
                                $work_order_data['management_plan'] = $customField['value'] ?? '';
                            } elseif ($customField['fieldName'] == 'closing comment') {
                                $work_order_data['closing_comments'] = $customField['value'] ?? '';
                            }
                        }
                    }

                    $workOrder->update($work_order_data);

                    // $this->processNotes($data, $workOrder->id);

                    if (! empty($customFieldData)) {
                        DB::table('work_order_custom_fields')->where('work_order_id', $workOrder->id)->delete();
                        DB::table('work_order_custom_fields')->insert($customFieldData);
                    }

                    if (! empty($data['assignedVendors'])) {
                        $incomingVendorIds = [];

                        foreach ($data['assignedVendors'] as $vendor) {
                            $vendorId = DB::table('vendors')
                                ->where('propertyware_id', $vendor['id'])
                                ->value('id');

                            if (! $vendorId) {
                                $vendorId = $this->createVendor($vendor);
                            }

                            $incomingVendorIds[] = $vendorId;

                            DB::table('work_order_vendors')->updateOrInsert(
                                ['vendor_id' => $vendorId, 'work_order_id' => $workOrder->id],
                                [
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );
                        }

                        // REMOVE vendors not in the new list
                        DB::table('work_order_vendors')
                            ->where('work_order_id', $workOrder->id)
                            ->whereNotIn('vendor_id', $incomingVendorIds)
                            ->delete();
                    }

                    $processedCount++;
                }

                unset($workOrderChunk);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            if ($processedCount === 0) {
                Log::warning('No work orders returned from Propertyware API.');

                return;
            }

            Log::info('Successfully updated Work order details! Work Order Count: '.$processedCount);
        } catch (\Throwable $th) {
            Log::error('Updating Work order failed: '.$th->getMessage());
        }

    }

    private function findOrCreateBuilding(int $propertywareId): int
    {
        if (isset($this->buildingCache[$propertywareId])) {
            return $this->buildingCache[$propertywareId];
        }

        $existing = Building::where('propertyware_id', $propertywareId)->first();

        if ($existing) {
            return $this->buildingCache[$propertywareId] = $existing->propertyware_id;
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
            'is_active' => $vendorData['active'],
        ];

        $vendor = Vendor::create($vendorsData);

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
