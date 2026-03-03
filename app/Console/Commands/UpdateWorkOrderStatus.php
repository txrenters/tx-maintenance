<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $work_orders = $this->propertyWareService->getWorkOrdersViaRestAPI() ?? [];

        if (empty($work_orders)) {
            Log::warning('No work orders returned from Propertyware API.');

            return;
        }

        Log::info('Work Orders updates are running.');

        try {
            foreach (array_chunk($work_orders, 100) as $workOrderChunk) {
                foreach ($workOrderChunk as $order) {
                    $data = (array) $order;

                    $ID = $data['id'] ?? null;

                    if ($ID) {

                        $workOrder = WorkOrder::where('propertyware_id', $data['id'])->first();

                        if ($workOrder) {
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
                                        $work_order_data['closing_comments'] = empty($workOrder->closing_comments) ? $customField['value'] : $workOrder->closing_comments;
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
                        }
                    }
                }
            }

            Log::info('Successfully updated Work order details! Work Order Count: '.count($work_orders));
        } catch (\Throwable $th) {
            Log::error('Updating Work order failed: '.$th->getMessage());
        }

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