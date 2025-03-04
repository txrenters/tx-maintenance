<?php

namespace App\Console\Commands;

use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrderImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:work-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import work orders from PropertyWare API every minute';

    protected PropertyWareService $propertyWareService;

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $work_orders = collect($this->propertyWareService->getWorkOrders())->toArray();
        $now = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();

            $work_orders = json_decode(json_encode($work_orders), true);

            foreach($work_orders as $order){

                $data = (array)$order;

                $work_order_propertyware_id = $data['ID'] ?? null;
                $owner_propertyware_id = $data['owner']['ID'] ?? null;
                $tenant_propertyware_id = $data['requestedByContact']['ID'] ?? null;

                $tenant = '';
                $owner = '';
                $work_order_data = '';

                if($tenant_propertyware_id){
                    $tenant = Tenants::where('propertyware_id', $data['requestedByContact']['ID'])->first();

                    if(!$tenant){
                        $tenantData = [
                            'client_data' => $data['requestedByContact']['clientData'] ?? null,
                            'propertyware_id' => $data['requestedByContact']['ID'] ?? null,
                            'first_name' => $data['requestedByContact']['firstName'] ?? null,
                            'middle_name' => $data['requestedByContact']['middleName'] ?? null,
                            'last_name' => $data['requestedByContact']['lastName'] ?? null,
                            'suffix' => $data['requestedByContact']['suffix'] ?? null,
                            'birth_date' => $data['requestedByContact']['birthDate'] ?? null,
                            'gender' => $data['requestedByContact']['gender'] == 1 ? 'Male' : "Female" ,
                            'email' => $data['requestedByContact']['email'] ?? null,
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
                            'documents' => !empty($data['requestedByContact']['documents']) ? json_encode($data['requestedByContact']['documents']) : null,
                            'notes' => !empty($data['requestedByContact']['notes']) ? json_encode($data['requestedByContact']['notes']) : null,

                        ];
                        $tenant = Tenants::create($tenantData);
                    }
                }


                if($owner_propertyware_id){
                    $owner = Owner::where('propertyware_id', $data['owner']['ID'])->first();

                    if(!$owner){
                        $ownerData = [
                            'client_data' => $data['owner']['clientData'] ?? null,
                            'propertyware_id' => $data['owner']['ID'] ?? null,
                            'first_name' => $data['owner']['firstName'] ?? null,
                            'last_name' => $data['owner']['lastName'] ?? null,
                            'email' => $data['owner']['email'] ?? null,
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
                        ];
                        $owner = Owner::create($ownerData);
                    }
                   
                }

                if($work_order_propertyware_id){
                    $woc = User::role('woc')->first();

                    $work_order_data = [
                        'client_data' => $data['clientData'] ?? null,
                        'propertyware_id' => $data['ID'] ?? null,
                        'work_order_no' => $data['number'] ?? null,
                        'approval_comments' =>  $data['approvalComments'] ?? null,
                        'is_approved' => $data['approved'] ?? null,
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
                        'priority' => $data['priority'] ?? null,
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
                        'owner_full_name' => $data['ownerFullName'] ?? null,
                        'requested_by' => $data['requestedBy'] ?? null,
                        'building_id' => $data['building']['ID'] ?? null,
                        'lease_id' => $data['lease']['ID'] ?? null,
                        'portfolio_id' => $data['portfolio']['ID'] ?? null,
                        'unit_id' => !empty($data['unitIDs'][0]) ? $data['unitIDs'][0] : null,
                        'owner_id' => $owner->id ?? null,      
                        'tenant_id' => $tenant->id ?? null,                          
                        'user_id' => $woc?->id,              
                    ];

                    $vendorId = !empty($data['vendorIDs']) ? $data['vendorIDs'][0] : null;

                    if(!empty($vendorId)){
                        $work_order_data['vendor_id'] = Vendor::where('propertyware_id',$vendorId)->pluck('id')->first();
                    }

                    if(!empty($data['customFields'])){
                        foreach ($data['customFields'] as $customField) {
                            if ($customField['fieldName'] == 'Service Status') {
                                $service_status = ServiceStatus::where('name', $customField['value'])->first();
                                if($service_status) {
                                    $work_order_data['service_status_id'] = $service_status->id ?? '';
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
                }

                


                $work_order = WorkOrder::updateOrCreate(['propertyware_id' => $work_order_propertyware_id], $work_order_data);

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
                            'work_order_id' => $work_order->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

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
                            'work_order_id' => $work_order->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

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
                            'work_order_id' => $work_order->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                DB::table('work_order_documents')->insert($documentsData);

                if(!empty($data['lease']) && is_array($data['lease'])){
                    foreach($data['lease']['tenants'] as $tenant){
                        $tenant = Tenants::where('propertyware_id', $tenant['ID'])->first();
                        if(!$tenant){
                            $tenantData = [
                                'client_data' => $tenant['clientData'] ?? null,
                                'propertyware_id' =>  $tenant['ID'] ?? null,
                                'first_name' =>  $tenant['firstName'] ?? null,
                                'middle_name' =>  $tenant['middleName'] ?? null,
                                'last_name' =>  $tenant['lastName'] ?? null,
                                'suffix' =>  $tenant['suffix'] ?? null,
                                'birth_date' => !empty($tenant['birthDate']) ? Carbon::parse( $tenant['birthDate'])->toDateString() : null,
                                'gender' =>  $tenant['gender'] == 1 ? 'Male' : "Female",
                                'email' =>  $tenant['email'] ?? null,
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
                                'is_name_on_lease' =>  $tenant['NameOnLease'] ?? null,
                                'is_dirty' =>  $tenant['dirty'] ?? null,
                                'comments' =>  $tenant['comments'] ?? null,
                            ];
                            
                            $tenant = Tenants::create($tenantData);  

                           
                        
                        }
                        dd($tenant);

                        DB::table('work_order_tenants')->insert([
                            'work_order_id' => $work_order->id,
                            'tenant_id' => $tenant->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                       
                    }   
                }
                

                // if(!empty($data['portfolio'])){
                //     foreach($data['portfolio']['owners'] as $owner){
                //         $owner = Owner::where('propertyware_id', $owner['ID'])->first();
                //         if(!$owner){
                //             $ownerData = [
                //                 'client_data' => $owner['clientData'] ?? null,
                //                 'propertyware_id' =>  $owner['ID'] ?? null,
                //                 'contact_id' =>  $owner['contactId'] ?? null,
                //                 'name' =>  $owner['name'] ?? null,
                //                 'name_on_check' =>  $owner['nameOnCheck'] ?? null,
                //                 'first_name' =>  $owner['firstName'] ?? null,
                //                 'last_name' =>  $owner['lastName'] ?? null,
                //                 'email' =>  $owner['email'] ?? null,
                //                 'phone' =>  $owner['phone'] ?? null,
                //                 'home_phone' =>  $owner['homePhone'] ?? null,
                //                 'work_phone' =>  $owner['workPhone'] ?? null,
                //                 'work_telephone' =>  $owner['workTelePhone'] ?? null,
                //                 'address' =>  $owner['address'] ?? null,
                //                 'address2' =>  $owner['address2'] ?? null,
                //                 'city' =>  $owner['city'] ?? null,
                //                 'state' =>  $owner['state'] ?? null,
                //                 'country' =>  $owner['country'] ?? null,
                //                 'zip' =>  $owner['zip'] ?? null,
                //                 'company' =>  $owner['companyName'] ?? null,
                //                 'tax_id' =>  $owner['taxID'] ?? null,
                //                 'percentage_ownership' =>  $owner['percentageOwnership'] ?? null,
                //                 'notes' =>  $owner['notes'] ?? null,
                //              ];

                //              $owner = Owner::updateOrCreate(['propertyware_id' => $owner['ID']],$ownerData);
                //         }

                        

                //         DB::table('work_order_owners')->insert([
                //             'work_order_id' => $work_order->id,
                //             'tenant_id' => $owner->id,
                //             'created_at' => $now,
                //             'updated_at' => $now,
                //         ]);
                       
                //     }   
                // }

                // if(!empty($data['vendorIDs'])){
                //     foreach($data['vendorIDs'] as $vendor){
                //         $vendor = Vendor::where('propertyware_id', $vendor)->first();
                //         DB::table('work_order_vendor')->insert([
                //             'work_order_id' => $work_order->id,
                //             'vendor_id' => $vendor->id,
                //             'created_at' => $now,
                //             'updated_at' => $now,
                //         ]);

                //     }   
                // }


            }

            DB::commit();

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Vendor import failed: ' . $th->getMessage());
        }

    }
}
