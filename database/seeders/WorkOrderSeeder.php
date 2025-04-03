<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class WorkOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $json  = File::get(public_path('work_orders.json'));

        $work_orders = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format work orders.');
            return;
        }

        foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
            foreach ($workOrderChunk as $order) {

                $createdDate = Carbon::parse($order['createdDate']);

                if ($createdDate->lt(Carbon::create(2024, 11, 1))) {
                    continue; // Skip work orders created before November 2024
                }

                $propertyware_id = $order['_id'];

                $checkWorkOrder = WorkOrder::where('propertyware_id', $propertyware_id)->exists();

                if(!$checkWorkOrder){
                    // Process tenant and user
                    $tenantId = $this->getTenantId($order['requestedBy']);

                    // Process owner and user
                    $ownerId = $this->getOwnerId($order['ownerId']);

                    // Process work order and related data
                    $this->processWorkOrderAndRelatedData($order, $tenantId, $ownerId, $now);
                }
                
            }
        }

        Log::info('Work order imported successfully!');

    }

    private function getTenantId(string $tenant)
    {
        $tenantId = '';

        if ($tenant) {
            $fullName = explode(' ', $tenant, 2); // Splitting into first and last name
            
            $firstName = $fullName[0];
            $lastName = $fullName[1] ?? ''; // Handle cases where there's no last name

            // Query the database using both first name and last name
            $tenantId = DB::table('tenants')
                ->where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->value('id');
        }

        return $tenantId;
    }

    private function getOwnerId(string $owner)
    {
        $ownerId = '';

        if ($owner) {
            // Query the database using both first name and last name
            $ownerId = DB::table('owners')
                ->where('propertyware_id', $owner)
                ->value('id');
        }

        return $ownerId;
    }


    private function processWorkOrderAndRelatedData(array $data, ?int $tenant, ?int $owner, string $now): void
    {
        DB::beginTransaction();

        try {
            $work_order_propertyware_id = $data['ID'] ?? null;
            $woc = User::role('woc')->first();

            $work_order_data = [
                'propertyware_id' => $work_order_propertyware_id,
                'work_order_no' => $data['number'] ?? null,
                'approval_comments' => $data['approvalComments'] ?? null,
                'is_approved' => !empty($data['approved']) ? $data['approved'] : false,
                'approved_by' => $data['approvedBy'] ?? null,
                'approved_date' => !empty($data['approvedDate']) ? Carbon::parse($data['approvedDate'])->toDateString() : null,
                'authorized_to_enter' => $data['authorizedToEnter'] ?? null,
                'category' => $data['category'] ?? null,
                'closing_comments' => $data['closingComments'] ?? '',
                'completed_date' => !empty($data['completedDate']) ? Carbon::parse($data['completedDate'])->toDateString() : null,
                'cost_estimate' => $data['costEstimate'] ?? null,
                'created_date' => !empty($data['createdDate']) ? Carbon::parse($data['createdDate']) : null,
                'date_to_enter' => !empty($data['dateToEnter']) ? Carbon::parse($data['dateToEnter'])->toDateString() : null,
                'description' => $data['description'] ?? null,
                'hour_estimate' => $data['hourEstimate'] ?? null,
                'location' => $data['location'] ?? null,
                'priority' => !empty($data['priority']) ? $data['priority'] : false,
                'priority_as_int' => $data['priorityAsInt'] ?? null,
                'required_materials' => $data['requiredMaterials'] ?? null,
                'scheduled_end_date' => !empty($data['scheduledEndDate']) ? Carbon::parse($data['scheduledEndDate'])->toDateString() : null,
                'service_request_building' => $data['serviceRequestBuilding'] ?? null,
                'service_request_company_name' => $data['serviceRequestCompanyName'] ?? null,
                'service_request_contact_email' => $data['serviceRequestContactEmail'] ?? null,
                'service_request_contact_name' => $data['serviceRequestContactName'] ?? null,
                'service_request_contact_phone' => $data['serviceRequestContactPhone'] ?? null,
                'service_request_contact_phone_type' => $data['serviceRequestContactPhoneType'] ?? null,
                'service_request_unit' => $data['serviceRequestUnit'] ?? null,
                'source' => $data['source'] ?? null,
                'specific_location' => $data['specificLocation'] ?? null,
                'start_date' => !empty($data['startDate']) ? Carbon::parse($data['startDate'])->toDateString() : null,
                'status' => $data['status'] ?? null,
                'total_cost' => $data['totalCost'] ?? null,
                'total_hour_work' => $data['totalHourWork'] ?? null,
                'type' => $data['type'] ?? null,
                'building_id' => $data['buildingID'] ?? null,
                'lease_id' => $data['leaseId'] ?? null,
                'portfolio_id' => $data['portfolioID'] ?? null,
                'unit_id' => !empty($data['unitIDs']) ? $data['unitIDs'] : null,
                'owner_id' => $owner,
                'tenant_id' => !empty($tenant) ? (int) $tenant : null,
                'user_id' => $woc?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $service_status_id =  DB::table('service_status')
                ->whereLike('name','%' . ($data['serviceStatus'] ?? '') . '%')
                ->value('id');

            $work_order_data['service_status_id'] = $service_status_id ?? 1;

            $work_order_data['zone'] = $data['zone'] ?? '';
            $work_order_data['additional_work_needed_reschedule'] = $data['additionalWorkNeededReschedule'] ?? '';
            $work_order_data['management_plan'] = $data['managementPlan'] ?? '';


            DB::table('work_orders')->updateOrInsert(
                ['propertyware_id' => $work_order_propertyware_id],
                $work_order_data
            );

            $workOrderId = DB::table('work_orders')->where('propertyware_id', $work_order_propertyware_id)->value('id');

            $this->processTenants($data['id'], $workOrderId, $now);
            $this->processOwners($data['id'], $workOrderId, $now);

            DB::commit();

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work order processing failed for work order ID: ' . ($work_order_propertyware_id ?? 'unknown') . ' - ' . $th->getMessage());
            throw $th;
        }
    }

    private function processOwners($workOrderJsonId, $workOrderId, $now)
    {
        $json  = File::get(public_path('owners.json'));

        $owners = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format for owners.json.');
            return;
        }

        // Filter tenants based on workorderid
        $filteredTenants = array_filter($owners, function($owner) use ($workOrderJsonId) {
            return isset($owner['workOrderId']) && $owner['workOrderId'] == $workOrderJsonId;
        });

        $work_order_owner_data = [];

        foreach($filteredTenants as $owner){
            $ownerId = DB::table('owners')->where('propertyware_id', $owner['_id'])->value('id');

            if ($ownerId == null) {
                Log::warning("Owner not found for Propertyware ID: {$owner['_id']}");
                continue; // Skip this tenant if not found
            }

            $work_order_owner_data[] = [
                'work_order_id' => $workOrderId,
                'tenant_id' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('work_order_owners')->insert($work_order_owner_data);

    }

    private function processTenants($workOrderJsonId, $workOrderId, $now)
    {
        $json  = File::get(public_path('tenants.json'));

        $tenants = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format for tenants.');
            return;
        }

        // Filter tenants based on workorderid
        $filteredTenants = array_filter($tenants, function($tenant) use ($workOrderJsonId) {
            return isset($tenant['workOrderId']) && $tenant['workOrderId'] == $workOrderJsonId;
        });

        $work_order_tenant_data = [];

        foreach($filteredTenants as $tenant){
            $tenantId = DB::table('tenants')->where('propertyware_id', $tenant['_id'])->value('id');

            if ($tenantId == null) {
                Log::warning("Tenant not found for Propertyware ID: {$tenant['_id']}");
                continue; // Skip this tenant if not found
            }

            $work_order_tenant_data[] = [
                'work_order_id' => $workOrderId,
                'tenant_id' => $tenantId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('work_order_tenants')->insert($work_order_tenant_data);

    }
}
