<?php

namespace Database\Seeders;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Local-only helper: seed one demo work order that shows the "vendor assigned"
 * owner notification (moment #2) in its AFTER state — the owner<->WOC thread
 * holding the assignment text, with the real tenant street address.
 *
 * It runs the real SendVendorWorkOrderInformation job so the demo can never
 * drift from production, but Bus/Mail/Http are faked, so no SMS, no vendor
 * email, and no PropertyWare call ever leave the box.
 */
class DemoOwnerAssignmentSeeder extends Seeder
{
    private const WO_NO = 99051;

    private const VENDOR_EMAIL = 'demo-vendor-assign@texasrenters.local';

    private const OWNER_EMAIL = 'demo-owner-assign@texasrenters.local';

    private const TENANT_EMAIL = 'demo-tenant-assign@texasrenters.local';

    public function run(): void
    {
        // Clean any previous run of this seeder (the demo WO + its rows).
        $oldIds = WorkOrder::query()->where('work_order_no', self::WO_NO)->pluck('id');

        if ($oldIds->isNotEmpty()) {
            Conversation::whereIn('work_order_id', $oldIds)->delete();
            DB::table('work_order_owners')->whereIn('work_order_id', $oldIds)->delete();
            DB::table('work_order_vendors')->whereIn('work_order_id', $oldIds)->delete();
            WorkOrder::whereIn('id', $oldIds)->delete();
        }

        // Vendor phone is read from the linked user ($vendor->phone => user->phone).
        $vendorUser = User::updateOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'name' => 'Professionals Same Day Repair (DEMO)',
                'phone' => '8327084891',
                'password' => bcrypt(self::VENDOR_EMAIL),
            ],
        );

        $vendor = Vendor::updateOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'propertyware_id' => 999000051,
                'name' => 'Professionals Same Day Repair (DEMO)',
                'vendor_type' => 'General',
                'is_active' => true,
                'user_id' => $vendorUser->id,
            ],
        );

        $ownerUser = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'Yi Hua (DEMO)', 'password' => bcrypt(self::OWNER_EMAIL)],
        );

        $owner = Owner::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'first_name' => 'Yi',
                'last_name' => 'Hua',
                'phone' => '8610577655',
                'percentage_ownership' => 100,
                'user_id' => $ownerUser->id,
            ],
        );

        $tenantUser = User::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            ['name' => 'Dana Tenant (DEMO)', 'password' => bcrypt(self::TENANT_EMAIL)],
        );

        $tenant = Tenants::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            [
                'first_name' => 'Dana',
                'last_name' => 'Tenant',
                'address' => '3326 Jane Way',
                'user_id' => $tenantUser->id,
            ],
        );

        $workOrder = WorkOrder::create([
            'work_order_no' => self::WO_NO,
            'propertyware_id' => 999000051,
            'description' => 'Light gas smell in attic next to a/c unit. Need to further investigate.',
            'location' => '3326 Jane Way',
            'category' => 'Plumbing',
            'priority' => 'Medium',
            'status' => 'Open',
            'type' => 'Repair',
            'service_status_id' => 1,
            'tenant_id' => $tenant->id,
            'created_date' => now(),
        ]);

        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'demo-assign-'.self::WO_NO]);
        $workOrder->owners()->attach($owner->id);

        // Run the real job with every outbound channel faked: no vendor email
        // (Mail), no SMS jobs (Bus), no PropertyWare upload (Http). The owner
        // conversation row is written directly by the job, so it persists.
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Bus::fake();
        Mail::fake();
        Http::fake();

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $count = $workOrder->owner_conversation()->count();

        $this->command?->info('Seeded demo Work Order #'.self::WO_NO." with {$count} owner message(s) — nothing sent.");
        $this->command?->info('Open it in the app and view the Owner conversation tab.');
    }
}
