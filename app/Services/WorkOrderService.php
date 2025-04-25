<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderService
{
    public function handle(array $workOrder): void
    {
        $data = $workOrder;

        $work_orders = collect($data)->toArray();
        $now = now()->format('Y-m-d H:i:s');
        Log::info('Work Orders import is running.');

        foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
            foreach ($workOrderChunk as $order) {
                $data = (array) $order;

                $ID = $data['ID'] ?? null;

                $workOrderExist = DB::table('work_orders')->where('propertyware_id', $ID)->exists();

                if ($workOrderExist) {
                    Log::info('Work order already exists, skipping.', ['ID' => $ID]);

                    continue;
                }

                if ($ID) {
                    // Process tenant and user
                    $tenant = $this->processTenantAndUser($data);

                    // Process owner and user
                    $owner = $this->processOwnerAndUser($data);

                    // Process work order and related data
                    $this->processWorkOrderAndRelatedData($data, $tenant, $owner, $now);
                }

            }
        }

        Log::info('Work order imported successfully!');
    }

    private function processTenantAndUser(array $data): ?int
    {
        $tenant_propertyware_id = $data['requestedByContact']['ID'] ?? null;

        $tenantExist = DB::table('tenants')->where('propertyware_id', $tenant_propertyware_id)->exists();

        if ($tenantExist) {
            Log::info('Tenant already exists, skipping.', ['ID' => $tenant_propertyware_id]);

            return DB::table('tenants')->where('propertyware_id', $tenant_propertyware_id)->value('id');
        }

        if (! $tenant_propertyware_id) {
            return null;
        }

        $tenantEmail = $data['requestedByContact']['email'] ?? $tenant_propertyware_id.'@texasrenter.com';

        $address = trim(implode(' ', array_filter([
            $data['requestedByContact']['address'] ?? null,
            $data['requestedByContact']['address2'] ?? null,
            $data['requestedByContact']['city'] ?? null,
            $data['requestedByContact']['state'] ?? null,
            $data['requestedByContact']['country'] ?? null,
            $data['requestedByContact']['zip'] ?? null,
        ])));

        $usersData = [
            'email' => $tenantEmail,
            'name' => $data['requestedByContact']['firstName'].' '.$data['requestedByContact']['lastName'],
            'phone' => $data['requestedByContact']['homePhone'] ?? null,
            'company' => $data['requestedByContact']['company'] ?? null,
            'address' => $address,
            'password' => bcrypt($tenantEmail),
        ];

        $user = $this->createOrUpdateUser($usersData, 'tenant');

        $tenantData = [
            'client_data' => $data['requestedByContact']['clientData'] ?? null,
            'propertyware_id' => $tenant_propertyware_id,
            'first_name' => $data['requestedByContact']['firstName'] ?? null,
            'middle_name' => $data['requestedByContact']['middleName'] ?? null,
            'last_name' => $data['requestedByContact']['lastName'] ?? null,
            'suffix' => $data['requestedByContact']['suffix'] ?? null,
            'birth_date' => $data['requestedByContact']['birthDate'] ?? null,
            'gender' => $data['requestedByContact']['gender'] == 1 ? 'Male' : 'Female',
            'email' => $tenantEmail,
            'fax' => $data['requestedByContact']['fax'] ?? null,
            'pager' => $data['requestedByContact']['pager'] ?? null,
            'home_phone' => $data['requestedByContact']['homePhone'] ?? null,
            'work_phone' => $data['requestedByContact']['workPhone'] ?? null,
            'mobile_phone' => $data['requestedByContact']['mobilePhone'] ?? null,
            'address' => $data['requestedByContact']['address'] ?? null,
            'address2' => $data['requestedByContact']['address2'] ?? null,
            'city' => $data['requestedByContact']['city'] ?? null,
            'state' => $data['requestedByContact']['state'] ?? null,
            'country' => $data['requestedByContact']['country'] ?? null,
            'zip' => $data['requestedByContact']['zip'] ?? null,
            'web_address' => $data['requestedByContact']['webAddress'] ?? null,
            'job_title' => $data['requestedByContact']['jobTitle'] ?? null,
            'company' => $data['requestedByContact']['company'] ?? null,
            'ssn' => $data['requestedByContact']['ssn'] ?? null,
            'search_tag' => $data['requestedByContact']['searchTag'] ?? null,
            'salutation' => $data['requestedByContact']['salutation'] ?? null,
            'name_on_check' => $data['requestedByContact']['nameOnCheck'] ?? null,
            'is_name_on_lease' => $data['requestedByContact']['namedOnLease'] ?? null,
            'is_dirty' => $data['requestedByContact']['dirty'] ?? null,
            'comments' => $data['requestedByContact']['comments'] ?? null,
            'user_id' => $user->id,
        ];

        DB::table('tenants')->updateOrInsert(
            ['propertyware_id' => $tenant_propertyware_id],
            $tenantData
        );

        return DB::table('tenants')->where('propertyware_id', $tenant_propertyware_id)->value('id');
    }

    private function processOwnerAndUser(array $data): ?int
    {
        $owner_propertyware_id = $data['owner']['ID'] ?? null;
        if (! $owner_propertyware_id) {
            return null;
        }

        $tenantExist = DB::table('owners')->where('propertyware_id', $owner_propertyware_id)->exists();

        if ($tenantExist) {
            Log::info('Owner already exists, skipping.', ['ID' => $owner_propertyware_id]);

            return DB::table('owners')->where('propertyware_id', $owner_propertyware_id)->value('id');
        }

        $ownerEmail = $data['owner']['email'] ?? $owner_propertyware_id.'@texasrenter.com';
        $address = trim(implode(' ', array_filter([
            $data['owner']['address'] ?? null,
            $data['owner']['address2'] ?? null,
            $data['owner']['city'] ?? null,
            $data['owner']['state'] ?? null,
            $data['owner']['country'] ?? null,
            $data['owner']['zip'] ?? null,
        ])));

        $usersData = [
            'email' => $ownerEmail,
            'name' => $data['owner']['firstName'].' '.$data['owner']['lastName'],
            'phone' => $data['owner']['homePhone'] ?? null,
            'company' => $data['owner']['company'] ?? null,
            'address' => $address,
            'password' => bcrypt($ownerEmail),
        ];

        $user = $this->createOrUpdateUser($usersData, 'owner');

        $ownerData = [
            'client_data' => $data['owner']['clientData'] ?? null,
            'propertyware_id' => $owner_propertyware_id,
            'first_name' => $data['owner']['firstName'] ?? null,
            'last_name' => $data['owner']['lastName'] ?? null,
            'email' => $ownerEmail,
            'fax' => $data['owner']['fax'] ?? null,
            'pager' => $data['owner']['pager'] ?? null,
            'home_phone' => $data['owner']['homePhone'] ?? null,
            'work_phone' => $data['owner']['workPhone'] ?? null,
            'mobile_phone' => $data['owner']['mobile'] ?? null,
            'address' => $data['owner']['address'] ?? null,
            'address2' => $data['owner']['address2'] ?? null,
            'city' => $data['owner']['city'] ?? null,
            'state' => $data['owner']['state'] ?? null,
            'country' => $data['owner']['country'] ?? null,
            'zip' => $data['owner']['zip'] ?? null,
            'company' => $data['owner']['company'] ?? null,
            'is_property_restricted' => $data['owner']['propertyRestricted'] ?? null,
            'status' => $data['owner']['status'] ?? null,
            'org_id' => $data['owner']['orgId'] ?? null,
            'user_id' => $user->id,
        ];

        DB::table('owners')->updateOrInsert(
            ['propertyware_id' => $owner_propertyware_id],
            $ownerData
        );

        return DB::table('owners')->where('propertyware_id', $owner_propertyware_id)->value('id');
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);

        return $user;
    }

    private function processWorkOrderAndRelatedData(array $data, ?int $tenant, ?int $owner, string $now): void
    {
        DB::beginTransaction();
        try {
            $work_order_propertyware_id = $data['ID'] ?? null;
            $woc = User::role('woc')->first();

            $work_order_data = [
                'client_data' => $data['clientData'] ?? null,
                'propertyware_id' => $work_order_propertyware_id,
                'work_order_no' => $data['number'] ?? null,
                'approval_comments' => $data['approvalComments'] ?? null,
                'is_approved' => ! empty($data['approved']) ? $data['approved'] : false,
                'approved_by' => ! empty($data['approvedBy']['firstName']) ? $data['approvedBy']['firstName'].' '.$data['approvedBy']['lastName'] : null,
                'approved_date' => ! empty($data['approvedDate']) ? Carbon::parse($data['approvedDate'])->toDateString() : null,
                'authorized_to_enter' => $data['authorizedToEnter'] ?? null,
                'category' => $data['category'] ?? null,
                'closing_comments' => $data['closingComments'] ?? '',
                'completed_date' => ! empty($data['completedDate']) ? Carbon::parse($data['completedDate'])->toDateString() : null,
                'cost_estimate' => $data['costEstimate'] ?? null,
                'created_date' => ! empty($data['createdDate']) ? Carbon::parse($data['createdDate']) : null,
                'date_to_enter' => ! empty($data['dateToEnter']) ? Carbon::parse($data['dateToEnter'])->toDateString() : null,
                'description' => $data['description'] ?? null,
                'hour_estimate' => $data['hourEstimate'] ?? null,
                'location' => $data['location'] ?? null,
                'priority' => ! empty($data['priority']) ? $data['priority'] : false,
                'priority_as_int' => $data['priorityAsInt'] ?? null,
                'required_materials' => $data['requiredMaterials'] ?? null,
                'scheduled_end_date' => ! empty($data['scheduledEndDate']) ? Carbon::parse($data['scheduledEndDate'])->toDateString() : null,
                'service_request_building' => $data['serviceRequestBuilding'] ?? null,
                'service_request_company_name' => $data['serviceRequestCompanyName'] ?? null,
                'service_request_contact_email' => $data['serviceRequestContactEmail'] ?? null,
                'service_request_contact_name' => $data['serviceRequestContactName'] ?? null,
                'service_request_contact_phone' => $data['serviceRequestContactPhone'] ?? null,
                'service_request_contact_phone_type' => $data['serviceRequestContactPhoneType'] ?? null,
                'service_request_unit' => $data['serviceRequestUnit'] ?? null,
                'source' => $data['source'] ?? null,
                'specific_location' => $data['specificLocation'] ?? null,
                'start_date' => ! empty($data['startDate']) ? Carbon::parse($data['startDate'])->toDateString() : null,
                'status' => $data['status'] ?? null,
                'total_cost' => $data['totalCost'] ?? null,
                'total_hour_work' => $data['totalHourWork'] ?? null,
                'type' => $data['type'] ?? null,
                'building_id' => $data['building']['ID'] ?? null,
                'lease_id' => $data['lease']['ID'] ?? null,
                'portfolio_id' => $data['portfolio']['ID'] ?? null,
                'unit_id' => ! empty($data['unitIDs'][0]) ? $data['unitIDs'][0] : null,
                'owner_id' => $owner,
                'tenant_id' => ! empty($tenant) ? (int) $tenant : null,
                'user_id' => $woc?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $customFieldData = [];
            if (! empty($data['customFields']) && is_array($data['customFields'])) {
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
                    }
                }
            }

            DB::table('work_orders')->updateOrInsert(
                ['propertyware_id' => $work_order_propertyware_id],
                $work_order_data
            );

            $work_order = DB::table('work_orders')->where('propertyware_id', $work_order_propertyware_id)->value('id');

            DB::table('work_order_custom_fields')->where('work_order_id', $work_order)->delete();
            DB::table('work_order_custom_fields')->insert($customFieldData);

            $this->processRelatedData($data, $work_order, $now);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work order processing failed for work order ID: '.($work_order_propertyware_id ?? 'unknown').' - '.$th->getMessage());
            throw $th;
        }
    }

    private function processRelatedData(array $data, int $work_order, string $now): void
    {
        // Process custom fields, notes, documents, etc.
        $this->processNotes($data, $work_order, $now);
        $this->processVendors($data, $work_order, $now);
        // $this->processDocuments($data, $work_order, $now);
        $this->processTenants($data, $work_order, $now);
        $this->processOwners($data, $work_order, $now);
    }

    private function processNotes(array $data, int $work_order, string $now): void
    {
        $notesData = [];
        if (! empty($data['notes']) && is_array($data['notes'])) {
            foreach ($data['notes'] as $note) {
                $notesData[] = [
                    'propertyware_id' => $note['ID'] ?? null,
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

    private function processVendors(array $data, int $work_order, string $now): void
    {
        $vendorsData = [];
        if (! empty($data['vendorIDs']) && is_array($data['vendorIDs'])) {
            foreach ($data['vendorIDs'] as $vendor) {
                $vendorId = DB::table('vendors')->where('propertyware_id', $vendor)->value('id');
                $vendorExist = DB::table('work_order_vendors')->where('vendor_id', $vendorId)->exists();

                if (! $vendorExist && $vendorId) { // don't insert if exists
                    $vendorsData[] = [
                        'work_order_id' => $work_order,
                        'vendor_id' => $vendorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

            }
        }
        if ($vendorsData) {
            DB::table('work_order_vendors')->insert($vendorsData);
        }
    }

    private function processDocuments(array $data, int $work_order, string $now): void
    {
        $documentsData = [];
        if (! empty($data['documents']) && is_array($data['documents'])) {
            foreach ($data['documents'] as $document) {
                $documentsData[] = [
                    'propertyware_id' => $document['ID'] ?? null,
                    'client_data' => $document['clientData'] ?? null,
                    'description' => $document['description'] ?? null,
                    'created_by_id' => $document['createdById'] ?? null,
                    'file_data' => $document['fileData'] ?? null,
                    'file_type' => $document['fileType'] ?? null,
                    'file_name' => $document['fileName'] ?? null,
                    'is_private' => $document['private'] ?? false,
                    'is_publish_to_owner_portal' => $document['publishToOwnerPortal'] ?? null,
                    'is_publish_to_tenant_portal' => $document['publishToTenantPortal'] ?? null,
                    'system_id' => $document['systemId'] ?? null,
                    'work_order_id' => $work_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('work_order_documents')->where('work_order_id', $work_order)->delete();
        DB::table('work_order_documents')->insert($documentsData);
        // Log::info('Work Order Documents: ', ['data' => $documentsData]);

    }

    private function processTenants(array $data, int $work_order, string $now): void
    {
        if (! empty($data['lease']) && is_array($data['lease'])) {

            DB::table('work_order_tenants')->where('work_order_id', $work_order)->delete();
            $work_order_tenant_data = [];

            foreach ($data['lease']['tenants'] as $tenant) {
                $tenantEmail = $tenant['email'] ?? $tenant['ID'].'@texasrenter.com';
                $address = trim(implode(' ', array_filter([
                    $tenant['address'] ?? null,
                    $tenant['address2'] ?? null,
                    $tenant['city'] ?? null,
                    $tenant['state'] ?? null,
                    $tenant['country'] ?? null,
                    $tenant['zip'] ?? null,
                ])));

                $usersData = [
                    'email' => $tenantEmail,
                    'name' => ($tenant['firstName'] ?? '').' '.($tenant['lastName'] ?? ''),
                    'phone' => $tenant['homePhone'] ?? null,
                    'company' => $tenant['company'] ?? null,
                    'address' => $address,
                    'password' => bcrypt($tenantEmail),
                ];

                $user = $this->createOrUpdateUser($usersData, 'tenant');

                $tenantData = [
                    'client_data' => $tenant['clientData'] ?? null,
                    'propertyware_id' => $tenant['ID'] ?? null,
                    'first_name' => $tenant['firstName'] ?? null,
                    'middle_name' => $tenant['middleName'] ?? null,
                    'last_name' => $tenant['lastName'] ?? null,
                    'suffix' => $tenant['suffix'] ?? null,
                    'birth_date' => ! empty($tenant['birthDate']) ? Carbon::parse($tenant['birthDate'])->toDateString() : null,
                    'gender' => $tenant['gender'] == 1 ? 'Male' : 'Female',
                    'email' => $tenantEmail,
                    'fax' => $tenant['fax'] ?? null,
                    'pager' => $tenant['pager'] ?? null,
                    'home_phone' => $tenant['homePhone'] ?? null,
                    'work_phone' => $tenant['workPhone'] ?? null,
                    'mobile_phone' => $tenant['mobilePhone'] ?? null,
                    'address' => $tenant['address'] ?? null,
                    'address2' => $tenant['address2'] ?? null,
                    'city' => $tenant['city'] ?? null,
                    'state' => $tenant['state'] ?? null,
                    'country' => $tenant['country'] ?? null,
                    'zip' => $tenant['zip'] ?? null,
                    'web_address' => $tenant['webAddress'] ?? null,
                    'job_title' => $tenant['jobTitle'] ?? null,
                    'company' => $tenant['company'] ?? null,
                    'website' => $tenant['website'] ?? null,
                    'ssn' => $tenant['ssn'] ?? null,
                    'search_tag' => $tenant['searchTag'] ?? null,
                    'salutation' => $tenant['salutation'] ?? null,
                    'name_on_check' => $tenant['nameOnCheck'] ?? null,
                    'is_name_on_lease' => $tenant['namedOnLease'] ?? null,
                    'is_dirty' => $tenant['dirty'] ?? null,
                    'comments' => $tenant['comments'] ?? null,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                DB::table('tenants')->updateOrInsert(
                    ['propertyware_id' => $tenant['ID']],
                    $tenantData
                );

                $tenantId = DB::table('tenants')->where('propertyware_id', $tenant['ID'])->value('id');

                $work_order_tenant_data[] = [
                    'work_order_id' => $work_order,
                    'tenant_id' => $tenantId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($work_order_tenant_data)) {
                DB::table('work_order_tenants')->insert($work_order_tenant_data);
                // Log::info('Work order tenants save!');
            }
        }
    }

    private function processOwners(array $data, int $work_order, string $now): void
    {
        if (! empty($data['portfolio']) && is_array($data['portfolio']['owners'])) {
            DB::table('work_order_owners')->where('work_order_id', $work_order)->delete();
            $work_order_owner_data = [];

            foreach ($data['portfolio']['owners'] as $owner) {
                $ownerEmail = $owner['email'] ?? $owner['ID'].'@texasrenter.com';
                $address = trim(implode(' ', array_filter([
                    $owner['address'] ?? null,
                    $owner['address2'] ?? null,
                    $owner['city'] ?? null,
                    $owner['state'] ?? null,
                    $owner['country'] ?? null,
                    $owner['zip'] ?? null,
                ])));

                $usersData = [
                    'email' => $ownerEmail,
                    'name' => $owner['name'] ?? null,
                    'phone' => $owner['homePhone'] ?? null,
                    'company' => $owner['company'] ?? null,
                    'address' => $address,
                    'password' => bcrypt($ownerEmail),
                ];

                $user = $this->createOrUpdateUser($usersData, 'owner');

                $ownerData = [
                    'client_data' => $owner['clientData'] ?? null,
                    'propertyware_id' => $owner['ID'] ?? null,
                    'contact_id' => $owner['contactId'] ?? null,
                    'name' => $owner['name'] ?? null,
                    'name_on_check' => $owner['nameOnCheck'] ?? null,
                    'first_name' => $owner['firstName'] ?? null,
                    'last_name' => $owner['lastName'] ?? null,
                    'email' => $ownerEmail,
                    'phone' => $owner['phone'] ?? null,
                    'home_phone' => $owner['homePhone'] ?? null,
                    'work_telephone' => $owner['workTelePhone'] ?? null,
                    'address' => $owner['address'] ?? null,
                    'address2' => $owner['address2'] ?? null,
                    'city' => $owner['city'] ?? null,
                    'state' => $owner['state'] ?? null,
                    'country' => $owner['country'] ?? null,
                    'zip' => $owner['zip'] ?? null,
                    'company' => $owner['companyName'] ?? null,
                    'tax_id' => $owner['taxID'] ?? null,
                    'percentage_ownership' => $owner['percentageOwnership'] ?? null,
                    'notes' => $owner['notes'] ?? null,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $ownerRecord = Owner::updateOrCreate(
                    ['propertyware_id' => $owner['ID']],
                    $ownerData
                );

                $ownerId = $ownerRecord?->id;

                $work_order_owner_data[] = [
                    'work_order_id' => $work_order,
                    'owner_id' => $ownerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

            }

            if (! empty($work_order_owner_data)) {
                DB::table('work_order_owners')->insert($work_order_owner_data);
                // Log::info('Work order owners save!');

            }
        }
    }
}
