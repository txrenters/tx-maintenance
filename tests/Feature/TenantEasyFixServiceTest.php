<?php

namespace Tests\Feature;

use App\Ai\TenantEasyFixCriteria;
use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\TenantEasyFixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TenantEasyFixServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twilio.tenant_easy_fix_sms' => false]);
        $this->withVideo('disposal_jammed');

        // The factory takes the first status on file; keep the easy-fix
        // statuses from being it.
        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    private function service(): TenantEasyFixService
    {
        return app(TenantEasyFixService::class);
    }

    private function makeTenant(?string $phone = '5125559999', ?string $email = 'dana@example.com'): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => $email ?? 't'.uniqid().'@texasrenter.com',
            'mobile_phone' => $phone,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeBuilding(?string $includedAppliances = 'Refrigerator, washer and dryer'): Building
    {
        return Building::query()->create([
            'propertyware_id' => 'B-'.uniqid(),
            'name' => 'Elm',
            'address' => '500 Elm St',
            'city' => 'Houston',
            'state_region' => 'TX',
            'custom_fields' => $includedAppliances === null ? null : [
                ['fieldName' => 'Included Appliances', 'value' => $includedAppliances, 'dataType' => 'Text'],
            ],
        ]);
    }

    private function makeWorkOrder(array $attributes = [], ?Tenants $tenant = null): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'work_order_no' => 43900,
            'source' => 'Tenant Portal',
            'propertyware_id' => 900001,
            'lease_id' => 555001,
            'description' => 'Garbage disposal is humming but not turning',
            'category' => 'Garbage Disposal',
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ], $attributes));
    }

    private function withVideo(string $key, ?string $url = 'https://youtu.be/disposal'): void
    {
        $items = config('tenant_easy_fix.items');

        foreach ($items as $index => $item) {
            if ($item['key'] === $key) {
                $items[$index]['video_url'] = $url;
            }
        }

        config(['tenant_easy_fix.items' => $items]);
    }

    private function serviceStatus(string $name): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(['name' => $name], ['description' => $name]);
    }

    private function mockPropertyWare(bool $accepts = true, int $times = 1): void
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('updateServiceStatus')->times($times)->andReturn($accepts);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    public function test_a_disposal_request_is_judged_an_easy_fix_and_recorded_once(): void
    {
        $workOrder = $this->makeWorkOrder();

        $verdict = $this->service()->assess($workOrder);

        $this->assertSame('easy_fix', $verdict['kind']);
        $this->assertSame('disposal_jammed', $verdict['key']);
        $this->assertTrue($verdict['sendable']);

        $row = DB::table('work_orders')->where('id', $workOrder->id)->first();
        $this->assertSame('disposal_jammed', $row->easy_fix_key);
        $this->assertNotNull($row->easy_fix_assessed_at);
    }

    public function test_the_verdict_is_recorded_even_while_the_gate_is_off_and_not_sendable(): void
    {
        $this->withVideo('disposal_jammed', null);
        $workOrder = $this->makeWorkOrder();

        $verdict = $this->service()->assess($workOrder);

        $this->assertSame('disposal_jammed', $verdict['key']);
        $this->assertFalse($verdict['sendable']);
        $this->assertSame('disposal_jammed', DB::table('work_orders')->where('id', $workOrder->id)->value('easy_fix_key'));
    }

    public function test_a_stored_verdict_is_read_back_rather_than_recomputed(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->service()->assess($workOrder);

        // The description changing later does not move the verdict.
        $workOrder->update(['description' => 'Water heater is leaking in the garage']);

        $this->assertSame('disposal_jammed', $this->service()->assess($workOrder->fresh())['key']);
    }

    public function test_assessed_but_not_an_easy_fix_is_remembered_as_null(): void
    {
        $workOrder = $this->makeWorkOrder(['description' => 'Water heater is leaking in the garage', 'category' => 'Plumbing']);

        $this->assertNull($this->service()->assess($workOrder));

        $row = DB::table('work_orders')->where('id', $workOrder->id)->first();
        $this->assertNull($row->easy_fix_key);
        $this->assertNotNull($row->easy_fix_assessed_at);
    }

    public function test_hoa_turnover_and_emergency_work_orders_are_never_easy_fixes(): void
    {
        foreach ([
            ['category' => WorkOrder::HOA_VIOLATION_CATEGORY],
            ['type' => 'Turnover'],
            ['category' => 'Re-key'],
            ['skip_automated_tasks' => true],
            ['is_emergency' => true],
            ['lease_id' => null, 'source' => 'Website'],
        ] as $attributes) {
            $workOrder = $this->makeWorkOrder($attributes);

            $this->assertNull($this->service()->assess($workOrder), json_encode($attributes));
        }
    }

    public function test_a_tenant_owned_washer_is_judged_an_appliance(): void
    {
        $building = $this->makeBuilding('refrigerator');
        $workOrder = $this->makeWorkOrder([
            'description' => 'Our washing machine will not spin',
            'category' => 'Washer',
            'building_id' => $building->propertyware_id,
        ]);

        $verdict = $this->service()->assess($workOrder);

        $this->assertSame('appliance', $verdict['kind']);
        $this->assertSame('appliance_washer', $verdict['key']);
        $this->assertTrue($verdict['sendable']);
    }

    public function test_an_included_or_unknown_appliance_is_left_alone(): void
    {
        $included = $this->makeWorkOrder([
            'description' => 'Our washing machine will not spin',
            'category' => 'Washer',
            'building_id' => $this->makeBuilding('Refrigerator, washer and dryer')->propertyware_id,
        ]);
        $this->assertNull($this->service()->assess($included));

        $unknown = $this->makeWorkOrder([
            'description' => 'Our washing machine will not spin',
            'category' => 'Washer',
            'building_id' => $this->makeBuilding(null)->propertyware_id,
        ]);
        $this->assertNull($this->service()->assess($unknown));
        $this->assertSame('appliance_ownership_unknown', $this->service()->judge($unknown)['reason']);
    }

    public function test_the_tenant_will_be_told_only_when_gate_item_mute_and_reach_all_allow(): void
    {
        $workOrder = $this->makeWorkOrder();
        $verdict = $this->service()->assess($workOrder);

        // Gate off.
        $this->assertFalse($this->service()->tenantWillBeTold($workOrder, $verdict));

        config(['services.twilio.tenant_easy_fix_sms' => true, 'services.twilio.tenant_intake_sms' => true]);
        $this->assertTrue($this->service()->tenantWillBeTold($workOrder, $verdict));

        // Not an easy fix.
        $this->assertFalse($this->service()->tenantWillBeTold($workOrder, null));

        // Muted on this work order.
        $workOrder->setAutomationPaused('tenant', true);
        $this->assertFalse($this->service()->tenantWillBeTold($workOrder->fresh(), $verdict));
    }

    public function test_the_tenant_counts_as_told_by_email_when_they_have_no_phone(): void
    {
        config(['services.twilio.tenant_easy_fix_sms' => true, 'services.twilio.tenant_intake_sms' => true, 'services.work_order.tenant_intake_email' => false]);

        $workOrder = $this->makeWorkOrder([], $this->makeTenant(null, 'dana@example.com'));
        $verdict = $this->service()->assess($workOrder);

        $this->assertFalse($this->service()->tenantWillBeTold($workOrder, $verdict));

        config(['services.work_order.tenant_intake_email' => true]);
        $this->assertTrue($this->service()->tenantWillBeTold($workOrder, $verdict));

        // A placeholder address is not a way to reach them.
        $unreachable = $this->makeWorkOrder(['work_order_no' => 43901], $this->makeTenant(null, null));
        $this->assertFalse($this->service()->tenantWillBeTold($unreachable, $this->service()->assess($unreachable)));
    }

    public function test_a_work_order_our_team_entered_is_not_told(): void
    {
        config(['services.twilio.tenant_easy_fix_sms' => true, 'services.twilio.tenant_intake_sms' => true]);

        $workOrder = $this->makeWorkOrder(['source' => 'Phone']);
        $verdict = $this->service()->assess($workOrder);

        $this->assertNotNull($verdict);
        $this->assertFalse($this->service()->tenantWillBeTold($workOrder, $verdict));
    }

    public function test_the_easy_fix_token_opens_as_already_notified_once(): void
    {
        $workOrder = $this->makeWorkOrder();

        $token = $this->service()->openEasyFixToken($workOrder);

        $this->assertSame(TenantUploadToken::PURPOSE_TENANT_EASY_FIX, $token->purpose);
        $this->assertSame(1, $token->notified_count);
        $this->assertNotNull($token->last_notified_at);

        // Reused, never duplicated.
        $this->assertTrue($token->is($this->service()->openEasyFixToken($workOrder)));
        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_the_status_is_pushed_to_propertyware_before_it_is_written_locally(): void
    {
        $status = $this->serviceStatus(TenantEasyFixService::EASY_FIX_STATUS);
        $this->mockPropertyWare(accepts: true);
        $workOrder = $this->makeWorkOrder();

        $this->service()->applyStatus($workOrder, TenantEasyFixCriteria::KIND_EASY_FIX);

        $this->assertSame($status->id, $workOrder->fresh()->service_status_id);
    }

    public function test_the_local_status_is_left_alone_when_propertyware_rejects_it(): void
    {
        $this->serviceStatus(TenantEasyFixService::EASY_FIX_STATUS);
        $this->mockPropertyWare(accepts: false);
        $workOrder = $this->makeWorkOrder();
        $before = $workOrder->service_status_id;

        $this->service()->applyStatus($workOrder, TenantEasyFixCriteria::KIND_EASY_FIX);

        $this->assertSame($before, $workOrder->fresh()->service_status_id);
    }

    public function test_the_appliance_kind_uses_the_non_real_property_status(): void
    {
        $status = $this->serviceStatus(TenantEasyFixService::APPLIANCE_STATUS);
        $this->mockPropertyWare(accepts: true);
        $workOrder = $this->makeWorkOrder();

        $this->service()->applyStatus($workOrder, TenantEasyFixCriteria::KIND_APPLIANCE);

        $this->assertSame($status->id, $workOrder->fresh()->service_status_id);
    }

    public function test_a_local_only_work_order_or_a_missing_status_never_touches_propertyware(): void
    {
        $this->mockPropertyWare(accepts: true, times: 0);

        $local = $this->makeWorkOrder(['propertyware_id' => null]);
        $before = $local->service_status_id;
        $this->serviceStatus(TenantEasyFixService::EASY_FIX_STATUS);
        $this->service()->applyStatus($local, TenantEasyFixCriteria::KIND_EASY_FIX);
        $this->assertSame($before, $local->fresh()->service_status_id);

        ServiceStatus::query()->where('name', TenantEasyFixService::EASY_FIX_STATUS)->delete();
        $remote = $this->makeWorkOrder(['work_order_no' => 43902]);
        $before = $remote->service_status_id;
        $this->service()->applyStatus($remote, TenantEasyFixCriteria::KIND_EASY_FIX);
        $this->assertSame($before, $remote->fresh()->service_status_id);
    }
}
