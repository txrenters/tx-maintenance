<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportWorkOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }


    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $work_orders = collect($this->data)->toArray();
        $now = now()->format('Y-m-d H:i:s');
        Log::info('Work Orders import is running.');

        DB::beginTransaction();

        try {
            $work_orders = json_decode(json_encode($work_orders), true);
            $chunkSize = 100; // Process 100 vendors at a time
            foreach (array_chunk($work_orders, $chunkSize) as $workOrderChunk) {
                foreach($workOrderChunk as $order){
                    $data = (array)$order;
                    $work_order_propertyware_id = $data['ID'] ?? null;
                    $owner_propertyware_id = $data['owner']['ID'] ?? null;
                    $tenant_propertyware_id = $data['requestedByContact']['ID'] ?? null;
                    
                    $tenant = '';
                    if($tenant_propertyware_id){
                        $tenantEmail = $data['requestedByContact']['email'] ?? $tenant_propertyware_id. "@texasrenter.com";

                        $address = trim(implode(' ', array_filter([
                            $data['requestedByContact']['address'] ?? null,
                            $data['requestedByContact']['address2'] ?? null,
                            $data['requestedByContact']['city'] ?? null,
                            $data['requestedByContact']['state'] ?? null,
                            $data['requestedByContact']['country'] ?? null,
                            $data['requestedByContact']['zip'] ?? null,
                        ])));                   
        
                        $usersData = [
                            'email' => $tenantEmail ?? null,
                            'name' => $data['requestedByContact']['firstName'].' '.$data['requestedByContact']['lastName'] ?? null,
                            'phone' => $data['requestedByContact']['homePhone']  ?? null,
                            'company' => $data['requestedByContact']['company'] ?? null,
                            'address' => $address,
                            'password' => bcrypt($tenantEmail ?? null), // Default password as email
                        ];
        
                        $user = User::updateOrCreate(['email' => $tenantEmail ?? null],$usersData);
                        $user->assignRole('tenant'); // Assign 'tenant' role

                        $tenantData = [
                            'client_data' => $data['requestedByContact']['clientData'] ?? null,
                            'propertyware_id' => $data['requestedByContact']['ID'] ?? null,
                            'first_name' => $data['requestedByContact']['firstName'] ?? null,
                            'middle_name' => $data['requestedByContact']['middleName'] ?? null,
                            'last_name' => $data['requestedByContact']['lastName'] ?? null,
                            'suffix' => $data['requestedByContact']['suffix'] ?? null,
                            'birth_date' => $data['requestedByContact']['birthDate'] ?? null,
                            'gender' => $data['requestedByContact']['gender'] == 1 ? 'Male' : "Female" ,
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
                        // update or create the tenants
                        DB::table('tenants')->updateOrInsert(
                            ['propertyware_id' => $data['requestedByContact']['ID']], 
                            $tenantData
                        );

                        $tenant = DB::table('tenants')->where('propertyware_id', $tenant_propertyware_id)->value('id') ?? null;
                    }
                    
                    $owner = '';

                    if($owner_propertyware_id){
                        
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
                            'name' => $data['owner']['firstName'].' '.$data['owner']['lastName'] ?? null,
                            'phone' => $data['owner']['homePhone']  ?? null,
                            'company' => $data['owner']['company'] ?? null,
                            'address' => $address,
                            'password' => bcrypt($data['owner']['email']), // Default password as email
                        ];
        
                        $user = User::updateOrCreate(['email' => $ownerEmail],$usersData);
                        $user->assignRole('owner'); // Assign 'owner' role

                        $ownerData = [
                            'client_data' => $data['owner']['clientData'] ?? null,
                            'propertyware_id' => $data['owner']['ID'] ?? null,
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
                        // update or create the owners
                        DB::table('owners')->updateOrInsert(
                            ['propertyware_id' => $data['owner']['ID']],
                            $ownerData
                        );

                        $owner = DB::table('owners')->where('propertyware_id', $owner_propertyware_id)->value('id');

                    }

                    $woc = User::role('woc')->first();

                    $work_order_data = [
                        'client_data' => $data['clientData'] ?? null,
                        'propertyware_id' => $data['ID'] ?? null,
                        'work_order_no' => $data['number'] ?? null,
                        'approval_comments' =>  $data['approvalComments'] ?? null,
                        'is_approved' => !empty($data['approved']) ? $data['approved'] : false,
                        'approved_by' => $data['approvedBy'] ?? null,
                        'approved_date' => !empty($data['approvedDate']) ? Carbon::parse($data['approvedDate'])->toDateString() : null,
                        'authorized_to_enter' => $data['authorizedToEnter'] ?? null,
                        'category' => $data['category'] ?? null,
                        'closing_comments' => $data['closingComments'] ?? null,
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
                        'scheduled_end_date' =>  !empty($data['scheduledEndDate']) ? Carbon::parse($data['scheduledEndDate'])->toDateString() : null,
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
                        'building_id' => $data['building']['ID'] ?? null,
                        'lease_id' => $data['lease']['ID'] ?? null,
                        'portfolio_id' => $data['portfolio']['ID'] ?? null,
                        'unit_id' => !empty($data['unitIDs'][0]) ? $data['unitIDs'][0] : null,
                        'owner_id' => $owner,      
                        'tenant_id' => !empty($tenant) ? (int) $tenant : null,
                        'user_id' => $woc?->id,  
                        'created_at' => $now,
                        'updated_at' => $now,            
                    ];

                    $vendorId = !empty($data['vendorIDs']) && is_array($data['vendorIDs']) ? $data['vendorIDs'][0] : null;
                    $work_order_data['vendor_id'] = DB::table('vendors')->where('propertyware_id', $vendorId)->value('id');

                    if(!empty($data['customFields']) && is_array($data['customFields'])){
                        foreach ($data['customFields'] as $customField) {
                            if ($customField['fieldName'] == 'Service Status') {
                                $service_status_id =  DB::table('service_status')->where('name', $customField['value'] ?? null)->value('id');
                                if($service_status_id) {
                                    $work_order_data['service_status_id'] = $service_status_id ?? '';
                                }
                            } else if ($customField['fieldName'] == 'zone') {
                                $work_order_data['zone'] = $customField['value'] ?? '';
                            } else if ($customField['fieldName'] == 'Additional work needed- Reschedule') {
                                $work_order_data['additional_work_needed_reschedule'] = $customField['value'] ?? '';
                            } else if ($customField['fieldName'] == 'Management Plan') {
                                $work_order_data['management_plan'] = $customField['value'] ?? '';
                            }
                        }
                    }

                    DB::table('work_orders')->updateOrInsert(
                        ['propertyware_id' => $work_order_propertyware_id], 
                        $work_order_data
                    );
                    
                    $work_order = DB::table('work_orders')->where('propertyware_id', $work_order_propertyware_id)->value('id');

                    $customFieldData = [];

                    if(!empty($data['customFields'])  && is_array($data['customFields'])){
                        foreach ($data['customFields'] as $customField) {
                            $customFieldData[] = [
                                'propertyware_id' => $customField['ID'] ?? null,
                                'client_data' => $customField['clientData'] ?? null,
                                'data_type' => $customField['dataType'] ?? null,
                                'definition_id' => $customField['definitionID'] ?? null,
                                'field_name' => $customField['fieldName'] ?? null,
                                'field_value' => $customField['value'] ?? null,
                                'work_order_id' => $work_order,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    DB::table('work_order_custom_fields')->where('work_order_id', $work_order)->delete();
                    DB::table('work_order_custom_fields')->insert($customFieldData);

                    $notesData = [];
                    if(!empty($data['notes']) && is_array($data['notes'])){
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

                    $documentsData = [];

                    if(!empty($data['documents']) && is_array($data['documents'])){
                        foreach ($data['documents'] as $document) {
                            $documentsData[] = [
                                'propertyware_id' => $document['ID'] ?? null,
                                'client_data' => $document['clientData'] ?? null,
                                'description' => $document['description'] ?? null,
                                'created_by_id' => $document['createdById'] ?? null,
                                'file_data' => $document['fileData'] ?? null,
                                'file_type' => $document['fileType'] ?? null,
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

                    if(!empty($data['lease']) && is_array($data['lease'])){
                        DB::table('work_order_tenants')->where('work_order_id', $work_order)->delete();
                        foreach($data['lease']['tenants'] as $tenant){
                            $tenantEmail =  $tenant['email'] ?? $tenant['ID']. '@texasrenter.com';
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
                                'name' => $tenant['firstName'] ?? null.' '.$tenant['lastName'] ?? null,
                                'phone' => $tenant['homePhone'] ?? null,
                                'company' => $tenant['company'] ?? null,
                                'address' => $address,
                                'password' => bcrypt($tenantEmail), // Default password as email
                            ];
                            $user = User::updateOrCreate(['email' => $tenantEmail],$usersData);
                            $user->assignRole('tenant'); // Assign 'owner' role
                            $tenantData = [
                                'client_data' => $tenant['clientData'] ?? null,
                                'propertyware_id' =>  $tenant['ID'] ?? null,
                                'first_name' =>  $tenant['firstName'] ?? null,
                                'middle_name' =>  $tenant['middleName'] ?? null,
                                'last_name' =>  $tenant['lastName'] ?? null,
                                'suffix' =>  $tenant['suffix'] ?? null,
                                'birth_date' => !empty($tenant['birthDate']) ? Carbon::parse( $tenant['birthDate'])->toDateString() : null,
                                'gender' =>  $tenant['gender'] == 1 ? 'Male' : "Female",
                                'email' =>  $tenantEmail,
                                'fax' =>  $tenant['fax'] ?? null,
                                'pager' =>  $tenant['pager'] ?? null,
                                'home_phone' =>  $tenant['homePhone'] ?? null,
                                'work_phone' =>  $tenant['workPhone'] ?? null,
                                'mobile_phone' =>  $tenant['mobilePhone'] ?? null,
                                'address' =>  $tenant['address'] ?? null,
                                'address2' =>  $tenant['address2'] ?? null,
                                'city' =>  $tenant['city'] ?? null,
                                'state' =>  $tenant['state'] ?? null,
                                'country' =>  $tenant['country'] ?? null,
                                'zip' =>  $tenant['zip'] ?? null,
                                'web_address' =>  $tenant['webAddress']?? null,
                                'job_title' =>  $tenant['jobTitle'] ?? null,
                                'company' =>  $tenant['company'] ?? null,
                                'website' =>  $tenant['website'] ?? null,
                                'ssn' =>  $tenant['ssn'] ?? null,
                                'search_tag' =>  $tenant['searchTag'] ?? null,
                                'salutation' =>  $tenant['salutation'] ?? null,
                                'name_on_check' =>  $tenant['nameOnCheck'] ?? null,
                                'is_name_on_lease' =>  $tenant['namedOnLease'] ?? null,
                                'is_dirty' =>  $tenant['dirty'] ?? null,
                                'comments' =>  $tenant['comments'] ?? null,
                                'user_id' => $user->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            DB::table('tenants')->updateOrInsert(
                                ['propertyware_id' => $tenant['ID']],
                                $tenantData
                            );
                            $tenant = DB::table('tenants')->where('propertyware_id', $tenant['ID'])->value('id');

                            DB::table('work_order_tenants')->insert([
                                'work_order_id' => $work_order,
                                'tenant_id' => $tenant,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }   
                    }

                    if(!empty($data['portfolio'])){
                        DB::table('work_order_owners')->where('work_order_id', $work_order)->delete();
                        foreach($data['portfolio']['owners'] as $owner){
                            $ownerEmail =  $owner['email'] ?? $owner['ID'] .'@texasrenter.com';
                            $address = trim(implode(' ', array_filter([
                                $owner['address'] ?? null,
                                $owner['address2'] ?? null,
                                $owner['city'] ?? null,
                                $owner['state'] ?? null,
                                $owner['country'] ?? null,
                                $owner['zip'] ?? null,
                            ])));                   
            
                            $usersData = [
                                'email' =>  $ownerEmail ,
                                'name' => $owner['name'] ?? null,
                                'phone' => $owner['homePhone'] ?? null,
                                'company' => $owner['company'] ?? null,
                                'address' => $address,
                                'password' => bcrypt( $ownerEmail ), // Default password as email
                            ];
            
                            $user = User::updateOrCreate(['email' =>  $ownerEmail ], $usersData);
                            $user->assignRole('owner'); // Assign 'owner' role
                            $ownerData = [
                                'client_data' => $owner['clientData'] ?? null,
                                'propertyware_id' =>  $owner['ID'] ?? null,
                                'contact_id' =>  $owner['contactId'] ?? null,
                                'name' =>  $owner['name'] ?? null,
                                'name_on_check' =>  $owner['nameOnCheck'] ?? null,
                                'first_name' =>  $owner['firstName'] ?? null,
                                'last_name' =>  $owner['lastName'] ?? null,
                                'email' =>   $ownerEmail ,
                                'phone' =>  $owner['phone'] ?? null,
                                'home_phone' =>  $owner['homePhone'] ?? null,
                                'work_telephone' =>  $owner['workTelePhone'] ?? null,
                                'address' =>  $owner['address'] ?? null,
                                'address2' =>  $owner['address2'] ?? null,
                                'city' =>  $owner['city'] ?? null,
                                'state' =>  $owner['state'] ?? null,
                                'country' =>  $owner['country'] ?? null,
                                'zip' =>  $owner['zip'] ?? null,
                                'company' =>  $owner['companyName'] ?? null,
                                'tax_id' =>  $owner['taxID'] ?? null,
                                'percentage_ownership' =>  $owner['percentageOwnership'] ?? null,
                                'notes' =>  $owner['notes'] ?? null,
                                'user_id' => $user->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            DB::table('owners')->updateOrInsert(
                                ['propertyware_id' => $owner['ID'] ?? null],
                                $ownerData
                            );
                            $owner = DB::table('owners')->where('propertyware_id', $owner['ID'])->value('id');
                            DB::table('work_order_owners')->insert([
                                'work_order_id' => $work_order,
                                'owner_id' => $owner,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }   
                    }

                    if(!empty($data['vendorIDs'])){
                        DB::table('work_order_vendors')->where('work_order_id', $work_order)->delete();
                        foreach($data['vendorIDs'] as $vendor){
                            $vendorData = Vendor::where('propertyware_id', $vendor)->first();
                            DB::table('work_order_vendors')->insert([
                                'work_order_id' => $work_order,
                                'vendor_id' => $vendorData->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }   
                    }
                }

                DB::commit();
            }

         
            Log::info('Work order imported successfully!');
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work order failed: ' . $th->getMessage());
        }
    }
}
