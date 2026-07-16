<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerServiceRequestNotificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Local-only helper: seed one demo work order that shows the "new service
 * request" owner notification in its AFTER (feature on) state — the owner<->WOC
 * conversation thread holding the confirmation text plus the description
 * follow-up, exactly as the live feature would post them.
 *
 * It runs the real OwnerServiceRequestNotificationService so the demo can never
 * drift from production wording, but faking the queue means the SMS job is only
 * recorded, never delivered — nothing is texted to any phone.
 */
class DemoOwnerServiceRequestSeeder extends Seeder
{
    private const WO_NO = 99050;

    private const OWNER_EMAIL = 'demo-owner-request@texasrenters.local';

    private const TENANT_EMAIL = 'demo-tenant-request@texasrenters.local';

    public function run(): void
    {
        // Clean any previous run of this seeder (the demo WO + its messages).
        $oldIds = WorkOrder::query()->where('work_order_no', self::WO_NO)->pluck('id');

        if ($oldIds->isNotEmpty()) {
            Conversation::whereIn('work_order_id', $oldIds)->delete();
            DB::table('work_order_owners')->whereIn('work_order_id', $oldIds)->delete();
            WorkOrder::whereIn('id', $oldIds)->delete();
        }

        // A demo property owner (highest ownership stake -> the primary owner the
        // notification targets). The phone is fake and never texted.
        $ownerUser = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'Marlene Wanzong (DEMO)', 'password' => bcrypt(self::OWNER_EMAIL)],
        );

        $owner = Owner::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'first_name' => 'Marlene',
                'last_name' => 'Wanzong',
                'mobile' => '2815550199',
                'percentage_ownership' => 100,
                'user_id' => $ownerUser->id,
            ],
        );

        // The tenant lives at the property, so their address is the property
        // address the message quotes.
        $tenantUser = User::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            ['name' => 'Dana Tenant (DEMO)', 'password' => bcrypt(self::TENANT_EMAIL)],
        );

        $tenant = Tenants::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            [
                'first_name' => 'Dana',
                'last_name' => 'Tenant',
                'mobile_phone' => '2815550123',
                'address' => '6341 Del Monte Dr',
                'user_id' => $tenantUser->id,
            ],
        );

        $workOrder = WorkOrder::create([
            'work_order_no' => self::WO_NO,
            'description' => 'There is water dripping from the roof down into the backyard.',
            'location' => '6341 Del Monte Dr',
            'category' => 'Plumbing',
            'priority' => 'Medium',
            'status' => 'Open',
            'type' => 'Repair',
            'service_status_id' => 1,
            'tenant_id' => $tenant->id,
            'created_date' => now(),
        ]);

        $workOrder->owners()->attach($owner->id);

        // Post the owner thread using the real feature logic. Faking the queue
        // records the SMS job without delivering it, so no phone is texted.
        config(['services.twilio.owner_service_request_sms' => true]);
        Queue::fake();

        // Re-fetch so the freshly attached owner relationship is loaded clean.
        app(OwnerServiceRequestNotificationService::class)
            ->notify(WorkOrder::findOrFail($workOrder->id));

        $count = $workOrder->owner_conversation()->count();

        $this->command?->info("Seeded demo Work Order #".self::WO_NO." with {$count} owner message(s) — no SMS sent.");
        $this->command?->info('Open it in the app and view the Owner conversation tab.');
    }
}
