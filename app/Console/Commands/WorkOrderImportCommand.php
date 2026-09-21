<?php

namespace App\Console\Commands;

use App\Jobs\AdoptCategorizedHoaViolationJob;
use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DescriptionChangeAlertService;
use App\Services\PropertyWareService;
use App\Services\WorkOrderLeaseService;
use App\Services\WorkOrderNoteSyncService;
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
    protected $description = 'Import the newest work orders (with SOAP-only nested data) from PropertyWare';

    protected PropertyWareService $propertyWareService;

    /**
     * WOC user resolved once per run instead of once per work order.
     */
    private ?User $wocUser = null;

    protected DescriptionChangeAlertService $descriptionChangeAlertService;

    protected WorkOrderLeaseService $leaseService;

    public function __construct(PropertyWareService $propertyWareService, DescriptionChangeAlertService $descriptionChangeAlertService, WorkOrderLeaseService $leaseService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
        $this->descriptionChangeAlertService = $descriptionChangeAlertService;
        $this->leaseService = $leaseService;
    }

    /**
     * Execute the console command.
     *
     * This is the "fast lane": it only imports the newest work orders and the
     * nested data the REST API cannot provide (tenant/owner/lease/notes).
     * Document retrieval and the bulk REST status sync run on their own
     * schedules so a new work order is never stuck waiting behind them.
     */
    public function handle(): void
    {
        $startedAt = microtime(true);

        $fetchStart = microtime(true);
        $work_orders = $this->propertyWareService->getWorkOrders() ?? [];
        $fetchMs = (int) round((microtime(true) - $fetchStart) * 1000);

        if (empty($work_orders)) {
            Log::warning('No work orders returned from Propertyware API.');

            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        Log::info('Work Orders import is running.', [
            'soap_fetch_ms' => $fetchMs,
            'work_order_count' => count($work_orders),
        ]);

        $this->wocUser = User::role('woc')->first();

        foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
            foreach ($workOrderChunk as $order) {
                $data = (array) $order;

                $ID = $data['ID'] ?? null;

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

        Log::info('Work order imported successfully!', [
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'soap_fetch_ms' => $fetchMs,
            'work_order_count' => count($work_orders),
        ]);
    }

    private function processWorkOrderAndRelatedData(array $data, ?int $tenant, ?int $owner, string $now): void
    {
        DB::beginTransaction();
        try {
            $work_order_propertyware_id = $data['ID'] ?? null;
            $woc = $this->wocUser;

            if (! empty($data['category'])) {
                DB::table('work_order_categories')->updateOrInsert(
                    ['name' => $data['category']],
                    ['updated_at' => now()]
                );
            }

            $work_order_data = [
                'client_data' => $data['clientData'] ?? null,
                'propertyware_id' => $work_order_propertyware_id,
                'work_order_no' => $data['number'] ?? null,
                'approval_comments' => $data['approvalComment'] ?? $data['approvalComments'] ?? null,
                'is_approved' => ! empty($data['approved']) ? $data['approved'] : false,
                'approved_by' => ! empty($data['approved']) && $data['approved'] ? $data['approvedBy']['ID'] ?? '' : null,
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
                'location' => ($data['building']['portfolio'] ?? '').' | '.($data['building']['abbreviation'] ?? ''),
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
                'property_manager_id' => $owner,
                'tenant_id' => ! empty($tenant) ? (int) $tenant : null,
                'user_id' => $woc?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // A payload without requestedByContact must not erase a tenant link
            // a previous import or an intake relink stamped: requested_by is
            // what every automated tenant message reads, so nulling it silently
            // kills the work order's whole tenant workflow (WO#43864, 2026-08-19).
            if (empty($tenant)) {
                unset($work_order_data['tenant_id']);
            }

            // A payload with blank approval fields must not erase the owner's
            // approval note (or its date/author) a previous import stamped:
            // they are entered only in PropertyWare, a PropertyWare-side blank
            // is usually the transient one our own SOAP updateWorkOrder push
            // just caused, and a local wipe is unrecoverable (WO#44014,
            // 2026-08-31 — same class as the tenant_id guard above).
            foreach (['approval_comments', 'approved_by', 'approved_date'] as $approvalField) {
                if (blank($work_order_data[$approvalField] ?? null)) {
                    unset($work_order_data[$approvalField]);
                }
            }

            $customFieldData = [];
            if (! empty($data['customFields']) && is_array($data['customFields'])) {
                foreach ($data['customFields'] as $customField) {
                    if ($customField['fieldName'] == 'Service Status') {

                        $service_status_id = DB::table('service_status')
                            ->whereLike('name', '%'.($customField['value'] ?? '').'%')
                            ->value('id');

                        $work_order_data['service_status_id'] = $service_status_id ?? 1;

                    } elseif ($customField['fieldName'] == 'Zone') {
                        $work_order_data['zone'] = $customField['value'];
                    } elseif ($customField['fieldName'] == 'Additional work needed- Reschedule') {
                        $work_order_data['additional_work_needed_reschedule'] = $customField['value'];
                    } elseif ($customField['fieldName'] == 'Management Plan') {
                        $work_order_data['management_plan'] = $customField['value'];
                    } elseif ($customField['fieldName'] == 'closing comment') {
                        $work_order_data['closing_comments'] = empty($work_order_data['closing_comments']) ? $customField['value'] : $work_order_data['closing_comments'];
                    }
                }
            }

            // DB::table('work_orders')->updateOrInsert(
            //     ['propertyware_id' => $work_order_propertyware_id],
            //     $work_order_data
            // );

            // Captured before the write so a PropertyWare-side description
            // edit, or a lease showing up on a lease-less work order, can be
            // detected below. WorkOrderScope is a no-op in console context,
            // so the lookup sees the row.
            $previousState = WorkOrder::query()
                ->where('propertyware_id', $work_order_propertyware_id)
                ->first();
            $previousDescription = $previousState?->description;
            $previousLeaseId = $previousState?->lease_id;

            // The app stamps "Tenant Portal" on the work orders it creates for
            // tenant portal requests, which PropertyWare reports as "None"; a
            // re-import must not wipe the stamp (same class as the tenant_id
            // guard above).
            if ($previousState !== null && ! WorkOrder::importedSourceReplaces($previousState->source, $work_order_data['source'] ?? null)) {
                unset($work_order_data['source']);
            }

            // service_status_id is NOT NULL: a payload without the "Service
            // Status" custom field would otherwise fail the insert outright —
            // and, since this lane only ever sees the newest pages, be retried
            // every run until it aged out of them and was never imported at
            // all. Same guard as WorkOrderService (the Import button path).
            if ($previousState === null && ! isset($work_order_data['service_status_id'])) {
                $work_order_data['service_status_id'] = DB::table('service_status')->where('name', 'New')->value('id') ?? 1;
            }

            // An existing row is only written where the payload really differs
            // (see WorkOrder::importChanges); the created_at/updated_at the
            // array stamps for the insert never reach an update, so every run
            // no longer rewrites created_at or marks the row as moved.
            // $work_order_data itself stays whole: the description and lease
            // checks after the write read the payload, not the diff.
            $columnsToWrite = $previousState === null
                ? $work_order_data
                : $previousState->importChanges(
                    array_diff_key($work_order_data, ['created_at' => null, 'updated_at' => null]),
                );

            $savedWorkOrder = WorkOrder::updateOrCreate(
                ['propertyware_id' => $work_order_propertyware_id],
                $columnsToWrite
            );

            $isNewWorkOrder = $savedWorkOrder->wasRecentlyCreated;

            $workOrder = WorkOrder::where('propertyware_id', $work_order_propertyware_id)
                ->with('service_status')
                ->first();

            DB::table('work_order_custom_fields')->where('work_order_id', $workOrder->id)->delete();
            DB::table('work_order_custom_fields')->insert($customFieldData);
            $this->processRelatedData($data, $workOrder->id, $now);

            DB::commit();

            // Alert staff when PropertyWare changed the description of an
            // existing work order (e.g. a tenant adding items to their
            // request after intake). After the commit so a rolled-back
            // change never alerts; the service itself never throws.
            if (! $isNewWorkOrder) {
                $this->descriptionChangeAlertService->detectAndAlert(
                    $workOrder,
                    $previousDescription,
                    $work_order_data['description'],
                    'soap_import'
                );
            }

            // PropertyWare attaches a website request's lease only on the work
            // order's next save, so a work order imported lease-less (and
            // therefore muted) can gain its lease on any later sync. Re-run the
            // intake messages it had to skip.
            if (! $isNewWorkOrder && $previousLeaseId === null && $workOrder->lease_id !== null) {
                $this->leaseService->handleArrival($workOrder, 'soap_import');
            }

            // Documents (and the vendor service-request email that depends on
            // them) are synced by the separate import:work-order-documents
            // schedule so this fast lane is never blocked by per-work-order
            // document API calls.

            // Queue the AI classification (vendor recommendation + emergency
            // assessment) for NEW work orders only. Existing work orders are
            // never re-classified, so a paid AI call runs at most once per
            // work order and an emergency status that is already set is never
            // updated again.
            if ($isNewWorkOrder) {
                GenerateWorkOrderRecommendationJob::dispatch($workOrder->id);

                // Notify the property owner that a new service request has come
                // in (confirmation + description texts). Gated off by default, so
                // this is a no-op until enabled in production. HOA violations
                // opt out inside the service — the owner is usually the one who
                // forwarded the notice.
                SendOwnerServiceRequestNotificationJob::dispatch($workOrder->id);

                // A work order raised in PropertyWare under the "HOA Violation"
                // category never passes through the notice upload screen, so
                // start its HOA workflow (Tenant Easy Fix, deadline, tenant
                // photo link) here instead. A no-op for every other category.
                AdoptCategorizedHoaViolationJob::dispatch($workOrder->id);

                // Email the tenant a branded confirmation carrying their
                // no-login portal link, so they can follow the request and add
                // photos without waiting to be asked.
                SendTenantWorkOrderIntakeEmailJob::dispatch($workOrder->id);

                // The same confirmation as a text, for the tenants who read
                // texts but not email. Independently gated, so either channel
                // can be turned off without the other.
                SendTenantServiceRequestNotificationJob::dispatch($workOrder->id);

                // A work order that came in without its lease is muted by the
                // vacant-home skip; show staff that rather than letting the
                // silence pass unnoticed.
                $this->leaseService->alertMissingOnIntake($workOrder);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Work order processing failed for work order no: '.($data['number'] ?? 'unknown').' - '.$th->getMessage());
        }
    }

    private function processRelatedData(array $data, int $work_order, string $now): void
    {
        // Process custom fields, notes, documents, etc.
        $this->processNotes($data, $work_order, $now);
        $this->processTenants($data, $work_order, $now);
        $this->processOwners($data, $work_order, $now);
    }

    private function processTenantAndUser(array $data): ?int
    {
        $tenant_propertyware_id = $data['requestedByContact']['ID'] ?? null;

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
            'phone' => $data['requestedByContact']['mobilePhone'] ?? $data['requestedByContact']['homePhone'],
            'company' => $data['requestedByContact']['company'] ?? null,
            'address' => $address,
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
            'phone' => $data['owner']['mobile'] ?? null,
            'company' => $data['owner']['company'] ?? null,
            'address' => $address,
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
        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            // bcrypt is expensive (~200ms); only hash when actually creating a
            // user instead of computing a throwaway hash for every work order.
            $data['password'] = bcrypt($data['email']);

            $user = User::create($data);
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * Refresh PropertyWare's notes on the work order. Notes written on the
     * dashboard are left alone — the old delete-and-reinsert here is what
     * made technician notes vanish minutes after they were saved.
     */
    private function processNotes(array $data, int $work_order, string $now): void
    {
        app(WorkOrderNoteSyncService::class)->syncFromPropertyWare(
            $work_order,
            is_array($data['notes'] ?? null) ? $data['notes'] : [],
        );
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
                    'phone' => $tenant['mobile'] ?? $tenant['homePhone'],
                    'company' => $tenant['company'] ?? null,
                    'address' => $address,
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
                    'gender' => $tenant['gender'] == 1 ? 'Male' : ($tenant['gender'] == 2 ? 'Female' : null),
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

                $work_order_tenant_data = [
                    'work_order_id' => $work_order,
                    'tenant_id' => $tenantId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                DB::table('work_order_tenants')->insert($work_order_tenant_data);
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

                DB::table('owners')->updateOrInsert(
                    ['propertyware_id' => $owner['ID']],
                    $ownerData
                );

                $ownerId = DB::table('owners')->where('propertyware_id', $owner['ID'])->value('id');

                $work_order_owner_data = [
                    'work_order_id' => $work_order,
                    'owner_id' => $ownerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                DB::table('work_order_owners')->insert($work_order_owner_data);

            }
        }
    }
}
