<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerServiceRequestNotificationService;
use App\Services\TenantEasyFixService;
use App\Services\TenantPortalLinkService;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Local-only helper: seed four demo work orders that show every tenant
 * easy-fix text in its AFTER (feature on) state, so the wording can be
 * screenshotted for sign-off:
 *
 *  - #990201 a humming garbage disposal   -> the easy-fix how-to text (handbook row 6)
 *  - #990203 a garage door that won't open -> the easy-fix how-to text (row 23)
 *  - #990204 a dryer not heating, dryer included with the home -> the handbook's dryer row (row 21)
 *  - #990202 a washer that won't spin, washer NOT included -> the tenant-responsibility text
 *
 * Each runs the real tenant and owner intake senders so the demo can never
 * drift from production wording. The work orders are local-only (no
 * PropertyWare id) so no status is pushed, and faking the queue records the
 * SMS jobs without delivering them: nothing is texted to any phone.
 *
 *   php artisan db:seed --class=DemoTenantEasyFixSeeder
 */
class DemoTenantEasyFixSeeder extends Seeder
{
    private const OWNER_EMAIL = 'demo-easyfix-owner@texasrenters.local';

    private const TENANT_EMAIL = 'demo-easyfix-tenant@texasrenters.local';

    /**
     * @var array<int, array{no: int, home: string, description: string, category: string}>
     */
    private const WORK_ORDERS = [
        ['no' => 990201, 'home' => 'DEMO-EASYFIX-1', 'description' => 'Garbage disposal is humming but not turning. Something might be stuck in it.', 'category' => 'Garbage Disposal'],
        ['no' => 990203, 'home' => 'DEMO-EASYFIX-1', 'description' => 'Garage door wont open', 'category' => 'General Maintenance'],
        ['no' => 990204, 'home' => 'DEMO-EASYFIX-1', 'description' => 'The dryer is not heating, clothes are still wet after a full cycle.', 'category' => 'Washer'],
        ['no' => 990202, 'home' => 'DEMO-EASYFIX-2', 'description' => 'Our washing machine will not spin, it fills up and then just stops.', 'category' => 'Washer'],
    ];

    public function run(): void
    {
        $this->cleanPreviousRun();

        $ownerUser = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'Olivia Owner (DEMO)', 'password' => bcrypt(self::OWNER_EMAIL)],
        );

        $owner = Owner::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['first_name' => 'Olivia', 'last_name' => 'Owner', 'mobile' => '3465550101', 'phone' => '3465550101', 'percentage_ownership' => 100, 'user_id' => $ownerUser->id],
        );

        $tenantUser = User::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            ['name' => 'Dana Tenant (DEMO)', 'password' => bcrypt(self::TENANT_EMAIL)],
        );

        $tenant = Tenants::updateOrCreate(
            ['email' => self::TENANT_EMAIL],
            ['first_name' => 'Dana', 'last_name' => 'Tenant', 'mobile_phone' => '5125550102', 'address' => '123 Demo St', 'user_id' => $tenantUser->id],
        );

        // Home 1 provides every appliance, so a dryer request gets the
        // handbook's dryer row; home 2 provides only a refrigerator, so a
        // washer request is the tenant's responsibility.
        $homes = [
            'DEMO-EASYFIX-1' => Building::updateOrCreate(
                ['propertyware_id' => 'DEMO-EASYFIX-1'],
                ['name' => '123 Demo St (easy fix)', 'address' => '123 Demo St', 'city' => 'Houston', 'state_region' => 'TX', 'custom_fields' => [['fieldName' => 'Included Appliances', 'value' => 'Refrigerator, washer and dryer', 'dataType' => 'Text']]],
            ),
            'DEMO-EASYFIX-2' => Building::updateOrCreate(
                ['propertyware_id' => 'DEMO-EASYFIX-2'],
                ['name' => '125 Demo St (tenant washer)', 'address' => '125 Demo St', 'city' => 'Houston', 'state_region' => 'TX', 'custom_fields' => [['fieldName' => 'Included Appliances', 'value' => 'Refrigerator', 'dataType' => 'Text']]],
            ),
        ];

        $newStatus = ServiceStatus::query()->where('name', 'New')->first() ?? ServiceStatus::query()->first();

        // Feature on for this run only; faking the queue records the SMS jobs
        // without delivering them.
        config([
            'services.twilio.tenant_intake_sms' => true,
            'services.twilio.owner_service_request_sms' => true,
            'services.twilio.tenant_easy_fix_sms' => true,
        ]);
        Queue::fake();

        foreach (self::WORK_ORDERS as $spec) {
            $home = $homes[$spec['home']];

            $workOrder = WorkOrder::create([
                'work_order_no' => $spec['no'],
                'status' => 'Open',
                'service_status_id' => $newStatus?->id,
                'source' => 'Tenant Portal',
                'description' => $spec['description'],
                'category' => $spec['category'],
                'type' => 'Repair',
                'priority' => 'Medium',
                'tenant_id' => $tenant->id,
                'building_id' => $home->propertyware_id,
                'location' => $home->name,
                'created_date' => now(),
                'local_status' => 'Created',
            ]);
            $workOrder->owners()->attach($owner->id);
            $workOrder->tenants()->attach($tenant->id);

            app(TenantServiceRequestNotificationService::class)->notify(WorkOrder::findOrFail($workOrder->id));
            app(OwnerServiceRequestNotificationService::class)->notify(WorkOrder::findOrFail($workOrder->id));

            $fresh = WorkOrder::findOrFail($workOrder->id);

            $this->command?->info('');
            $this->command?->info("=== Work Order #{$fresh->work_order_no} (id {$fresh->id}) - {$spec['description']}");
            $this->command?->info('    verdict: '.($fresh->easy_fix_key ?? 'none'));

            foreach ($fresh->tenant_conversation()->orderBy('id')->get() as $message) {
                $this->command?->line("--- Tenant text ---\n{$message->message}");
            }

            foreach ($fresh->owner_conversation()->orderBy('id')->get() as $message) {
                $this->command?->line("--- Owner text ---\n{$message->message}");
            }

            // For the first easy fix, also show the three check-ins that
            // follow on the next three weekdays when the tenant stays quiet.
            if ($spec['no'] === 990201) {
                $this->showFollowUps($fresh);
            }
        }

        $this->command?->info('');
        $this->command?->info('Seeded 4 demo work orders (search the board for 990201, 990203, 990204, 990202) - no SMS sent, nothing pushed to PropertyWare.');
    }

    /**
     * Replay the daily check-ins on the easy-fix token by backdating its last
     * notification one weekday at a time, exactly as the scheduled
     * `tenant-portal:send-links` run would find it.
     */
    private function showFollowUps(WorkOrder $workOrder): void
    {
        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->first();

        if ($token === null) {
            return;
        }

        $links = app(TenantPortalLinkService::class);

        for ($day = 1; $day <= TenantEasyFixService::FOLLOW_UP_DAYS; $day++) {
            // Re-read first: remind() stamps the row, and an in-memory copy
            // holding the same backdated value would not write it again.
            $token = $token->fresh();
            $token->forceFill(['last_notified_at' => now()->subWeekdays(1)->subMinute()])->save();

            if (! $links->remind($token->fresh())) {
                break;
            }

            $message = $workOrder->tenant_conversation()->orderByDesc('id')->first();
            $this->command?->line("--- Tenant check-in, weekday +{$day} ---\n{$message?->message}");
        }
    }

    /**
     * Remove the previous run's work orders and everything hanging off them.
     */
    private function cleanPreviousRun(): void
    {
        $ids = WorkOrder::query()->whereIn('work_order_no', array_column(self::WORK_ORDERS, 'no'))->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        Conversation::query()->whereIn('work_order_id', $ids)->delete();
        TenantUploadToken::query()->whereIn('work_order_id', $ids)->delete();
        DB::table('work_order_owners')->whereIn('work_order_id', $ids)->delete();
        DB::table('work_order_tenants')->whereIn('work_order_id', $ids)->delete();
        WorkOrder::query()->whereIn('id', $ids)->delete();
    }
}
