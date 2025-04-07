<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        $csvPath = public_path('work_orders.csv');

        if (! file_exists($csvPath)) {
            Log::error('CSV file not found: '.$csvPath);

            return;
        }

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file);

        if (! $header) {
            Log::error('Invalid CSV format in work_orders.csv.');

            return;
        }

        $workOrders = [];
        while ($row = fgetcsv($file)) {
            $workOrders[] = array_combine($header, $row);
        }
        fclose($file);

        foreach (array_chunk($workOrders, 100) as $workOrderChunk) {
            foreach ($workOrderChunk as $order) {

                if ($order['createdDate'] == 'NULL' || $order['createdDate'] == null) {
                    Log::warning('Work order createdDate is null for Propertyware ID: '.$order['_id']);

                    continue; // Skip this work order if createdDate is null
                }

                $createdDate = Carbon::parse($order['createdDate']);

                if ($createdDate->lt(Carbon::create(2024, 11, 1))) {
                    continue; // Skip work orders created before November 2024
                }

                $propertyware_id = $order['_id'];
                $checkWorkOrder = DB::table('work_orders')->where('propertyware_id', $propertyware_id)->exists();

                if (! $checkWorkOrder) {

                    $tenantId = $this->getTenantId($order['requestedBy']);

                    // Process owner and user
                    $ownerId = $this->getOwnerId($order['ownerId']);

                    $this->processWorkOrder($order, $tenantId, $ownerId, $now);
                }
            }
        }

        Log::info('Work orders imported successfully!');
    }

    private function getTenantId(string $tenant): ?string
    {
        return DB::table('tenants')
            ->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [trim($tenant)])
            ->value('id');
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

    private function processWorkOrder(array $data, $tenantId, $ownerId, string $now): void
    {
        DB::beginTransaction();
        try {

            $work_order_propertyware_id = $data['_id'] ?? null;
            $woc = User::role('woc')->first();

            $work_order_data = [
                'propertyware_id' => $work_order_propertyware_id,
                'work_order_no' => $data['number'] ?? null,
                'approval_comments' => $data['approvalComments'] ?? null,
                'is_approved' => ! empty($data['approved']) ? $data['approved'] : false,
                'approved_by' => $data['approvedBy'] ?? null,
                'approved_date' => ! empty($data['approvedDate']) ? Carbon::parse($data['approvedDate'])->toDateString() : null,
                'authorized_to_enter' => $data['authorizedToEnter'] ?? null,
                'category' => $data['category'] ?? null,
                'closing_comments' => $data['closingComments'] ?? '',
                'completed_date' => ! empty($data['completedDate']) && strtolower($data['completedDate']) != 'null'
                ? Carbon::parse($data['completedDate'])
                : null,
                'cost_estimate' => $data['costEstimate'] ?? null,
                'created_date' => ! empty($data['createdDate']) && strtolower($data['createdDate']) != 'null'
                ? Carbon::parse($data['createdDate'])
                : null,
                'date_to_enter' => ! empty($data['dateToEnter']) && strtolower($data['dateToEnter']) != 'null'
                ? Carbon::parse($data['dateToEnter'])
                : null,
                'description' => $data['description'] ?? null,
                'hour_estimate' => $data['hourEstimate'] ?? null,
                'location' => $data['location'] ?? null,
                'priority' => ! empty($data['priority']) ? $data['priority'] : null,
                'priority_as_int' => $data['priorityAsInt'] ?? null,
                'required_materials' => $data['requiredMaterials'] ?? null,
                'scheduled_end_date' => ! empty($data['scheduledEndDate']) && strtolower($data['scheduledEndDate']) != 'null'
                ? Carbon::parse($data['scheduledEndDate'])
                : null,
                'service_request_building' => $data['serviceRequestBuilding'] ?? null,
                'service_request_company_name' => $data['serviceRequestCompanyName'] ?? null,
                'service_request_contact_email' => $data['serviceRequestContactEmail'] ?? null,
                'service_request_contact_name' => $data['serviceRequestContactName'] ?? null,
                'service_request_contact_phone' => $data['serviceRequestContactPhone'] ?? null,
                'service_request_contact_phone_type' => $data['serviceRequestContactPhoneType'] ?? null,
                'service_request_unit' => $data['serviceRequestUnit'] ?? null,
                'source' => $data['source'] ?? null,
                'specific_location' => ! empty($data['specificLocation'])
                ? mb_convert_encoding($data['specificLocation'], 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252'])
                : null,
                'start_date' => ! empty($data['startDate']) && strtolower($data['startDate']) != 'null'
                ? Carbon::parse($data['startDate'])
                : null,
                'status' => $data['status'] ?? null,
                'total_cost' => $data['totalCost'] ?? null,
                'total_hour_work' => $data['totalHourWork'] ?? null,
                'type' => $data['type'] ?? null,
                'building_id' => $data['buildingID'] == 'NULL' ? '1' : $data['buildingID'] ?? null,
                'lease_id' => $data['leaseId'] == 'NULL' ? '1' : $data['leaseId'] ?? null,
                'portfolio_id' => $data['portfolioID'] ?? null,
                'unit_id' => ! empty($data['unitIDs']) ? $data['unitIDs'] : null,
                'owner_id' => $ownerId,
                'tenant_id' => ! empty($tenantId) ? (int) $tenantId : null,
                'user_id' => $woc?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $service_status_id = DB::table('service_status')
                ->whereLike('name', '%'.($data['serviceStatus'] ?? '').'%')
                ->value('id');

            $work_order_data['service_status_id'] = $service_status_id ?? 1;

            $work_order_data['zone'] = $data['zone'] ?? '';
            $work_order_data['additional_work_needed_reschedule'] = $data['additionalWorkNeededReschedule'] ?? '';
            $work_order_data['management_plan'] = $data['managementPlan'] ?? '';

            DB::table('work_orders')->insert($work_order_data);

            $workOrderId = DB::table('work_orders')->where('propertyware_id', $work_order_propertyware_id)->value('id');

            $this->processTenants($data['id'], $workOrderId, $now);
            $this->processOwners($data['id'], $workOrderId, $now);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work order processing failed for work order ID: '.($data['_id'] ?? 'unknown').' - '.$th->getMessage());
            throw $th;
        }
    }

    private function processOwners($workOrderJsonId, $workOrderId, $now)
    {
        $csvPath = public_path('owners.csv');

        if (! file_exists($csvPath)) {
            Log::error('CSV file not found: '.$csvPath);

            return;
        }

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file);
        if (! $header) {
            Log::error('Invalid CSV format in owners.csv.');

            return;
        }

        $owners = [];
        while ($row = fgetcsv($file)) {
            $owners[] = array_combine($header, $row);
        }
        fclose($file);

        $work_order_owner_data = [];

        foreach ($owners as $owner) {
            if ($owner['workOrderId'] != $workOrderJsonId) {
                continue;
            }

            $ownerId = DB::table('owners')->where('propertyware_id', $owner['_id'])->value('id');

            if (! $ownerId) {
                Log::warning("Owner not found for Propertyware ID: {$owner['_id']}");

                continue;
            }

            $work_order_owner_data[] = [
                'work_order_id' => $workOrderId,
                'owner_id' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('work_order_owners')->insert($work_order_owner_data);
    }

    private function processTenants($workOrderJsonId, $workOrderId, $now)
    {
        $csvPath = public_path('tenants.csv');

        if (! file_exists($csvPath)) {
            Log::error('CSV file not found: '.$csvPath);

            return;
        }

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file);
        if (! $header) {
            Log::error('Invalid CSV format in tenants.csv.');

            return;
        }

        $tenants = [];
        while ($row = fgetcsv($file)) {
            if (count($row) !== count($header)) {
                Log::warning('Skipping invalid row: '.json_encode($row));

                continue;
            }
            $tenants[] = array_combine($header, $row);
        }

        $work_order_tenant_data = [];

        foreach ($tenants as $tenant) {
            if ($tenant['workOrderId'] != $workOrderJsonId) {
                continue;
            }

            $tenantId = DB::table('tenants')->where('propertyware_id', $tenant['_id'])->value('id');

            if (! $tenantId) {
                Log::warning("Tenant not found for Propertyware ID: {$tenant['_id']}");

                continue;
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
