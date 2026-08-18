<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PropertyWare create carries no requestedByContact, so HOA work orders
 * imported back after creation had tenant_id NULL — and every automated tenant
 * message reads requested_by, so the whole HOA tenant workflow silently
 * no-oped while the vendor escalation still fired (WO#43864, 2026-08-19).
 * These tests pin the repair: the intake stamps the lease tenant, re-imports
 * keep the stamp, and hoa:relink-tenants repairs old rows.
 */
class HoaTenantRelinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'The service request is checking for an easy fix.'],
        );

        config(['services.twilio.hoa_violation_sms' => false]);
    }

    private function building(int $id = 7001): Building
    {
        return Building::query()->create([
            'propertyware_id' => $id,
            'name' => '123 Oak Ridge Dr',
            'address' => '123 Oak Ridge Dr',
            'portfolio_id' => 900,
        ]);
    }

    private function mockPropertyWare(?string $createdId): void
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('createWorkOrder')->andReturn($createdId);
        $mock->shouldReceive('getLatestWorkOrderForBuilding')
            ->andReturn(['location' => 'MULLERJJ | 3235QUARRYPL', 'unitIDs' => [4209311746]]);
        $mock->shouldReceive('getWorkOrder')->andReturn(['number' => 55123]);
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([]);
        $mock->shouldReceive('uploadWorkOrderPdf')->andReturn('doc-1');
        $mock->shouldReceive('updateServiceStatus')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    /**
     * @return array<string, mixed>
     */
    private function notice(int $buildingId): array
    {
        return [
            'building_id' => $buildingId,
            'pages' => [1],
            'description' => 'Trim the front lawn.',
            'violation_items' => ['Trim the front lawn'],
            'hoa_name' => 'Oak Ridge HOA',
            'notice_date' => now()->toDateString(),
        ];
    }

    public function test_pw_created_intake_links_the_lease_tenant_with_a_phone(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare('88999');

        // The row the import brings back: tenant-less, but with the lease
        // tenants on the pivot — exactly what the import writes.
        $imported = WorkOrder::factory()->create([
            'propertyware_id' => '88999',
            'work_order_no' => 55123,
            'tenant_id' => null,
        ]);
        $phoneless = Tenants::factory()->create(['mobile_phone' => null, 'home_phone' => null]);
        $reachable = Tenants::factory()->create(['mobile_phone' => '9402312400']);
        $imported->tenants()->attach([$phoneless->id, $reachable->id]);

        $building = $this->building();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        // The lease tenant with a phone is now the requester, so every
        // tenant-facing send has someone to text.
        $this->assertSame($reachable->id, $imported->refresh()->tenant_id);
    }

    public function test_pw_created_intake_actually_texts_the_linked_tenant_when_enabled(): void
    {
        config([
            'services.hoa.pw_create_enabled' => true,
            'services.twilio.hoa_violation_sms' => true,
            'services.twilio.maintenance_number' => '+15550001111',
        ]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare('88999');

        $imported = WorkOrder::factory()->create([
            'propertyware_id' => '88999',
            'work_order_no' => 55123,
            'tenant_id' => null,
        ]);
        $tenant = Tenants::factory()->create(['mobile_phone' => '9402312400']);
        $imported->tenants()->attach($tenant->id);

        $building = $this->building();

        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        // Before the relink this exact flow produced no conversation row at
        // all — the silent skip this whole fix exists to end.
        $this->assertTrue(
            Conversation::query()
                ->where('work_order_id', $imported->id)
                ->where('conversation_type', 'tenant')
                ->where('receiver_number', '+19402312400')
                ->exists(),
        );
    }

    public function test_adopting_a_categorized_work_order_links_the_lease_tenant(): void
    {
        Queue::fake();

        $workOrder = WorkOrder::factory()->create([
            'category' => WorkOrder::HOA_VIOLATION_CATEGORY,
            'status' => 'Open',
            'tenant_id' => null,
            'created_date' => now(),
        ]);
        $tenant = Tenants::factory()->create(['mobile_phone' => '5551230000']);
        $workOrder->tenants()->attach($tenant->id);

        $this->assertTrue(app(HoaViolationIntakeService::class)->adoptCategorizedWorkOrder($workOrder));

        $this->assertSame($tenant->id, $workOrder->refresh()->tenant_id);
        $this->assertTrue(
            $workOrder->tenantUploadTokens()
                ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
                ->exists(),
        );
    }

    public function test_reimport_without_requested_by_contact_keeps_the_tenant_link(): void
    {
        Queue::fake();
        Role::findOrCreate('woc', 'web');
        $tenant = Tenants::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => '424242',
            'work_order_no' => 60001,
            'tenant_id' => $tenant->id,
        ]);

        // A PropertyWare payload with no requestedByContact — what every HOA
        // create round-trip and plenty of plain syncs send.
        (new WorkOrderService)->handle([
            [
                'ID' => '424242',
                'number' => 60001,
                'status' => 'Open',
                'category' => 'General Maintenance',
                'type' => 'Service Request',
                'description' => 'Leaky faucet.',
                'priorityAsInt' => 0,
            ],
        ]);

        $this->assertSame($tenant->id, $workOrder->refresh()->tenant_id);
    }

    public function test_reimport_with_requested_by_contact_still_updates_the_tenant_link(): void
    {
        Queue::fake();
        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('tenant', 'web');

        $previous = Tenants::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => '424243',
            'work_order_no' => 60002,
            'tenant_id' => $previous->id,
        ]);

        (new WorkOrderService)->handle([
            [
                'ID' => '424243',
                'number' => 60002,
                'status' => 'Open',
                'category' => 'General Maintenance',
                'type' => 'Service Request',
                'description' => 'Leaky faucet.',
                'priorityAsInt' => 0,
                'requestedByContact' => [
                    'ID' => 909090,
                    'firstName' => 'Mark',
                    'lastName' => 'Bridenbeck',
                    'email' => 'mark.bridenbeck@example.com',
                    'mobilePhone' => '9402312400',
                    'namedOnLease' => true,
                    'dirty' => false,
                ],
            ],
        ]);

        $stamped = Tenants::query()->where('propertyware_id', 909090)->firstOrFail();
        $this->assertSame($stamped->id, $workOrder->refresh()->tenant_id);
    }

    public function test_relink_command_links_tenantless_hoa_work_orders(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43864, 'tenant_id' => null]);
        $tenant = Tenants::factory()->create([
            'first_name' => 'Mark',
            'last_name' => 'Bridenbeck',
            'mobile_phone' => '9402312400',
        ]);
        $workOrder->tenants()->attach($tenant->id);
        TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);

        // A non-HOA tenant-less work order must be left alone.
        $untouched = WorkOrder::factory()->create(['tenant_id' => null]);

        $this->artisan('hoa:relink-tenants', ['--dry-run' => true])
            ->expectsOutputToContain('would link Mark Bridenbeck')
            ->assertSuccessful();
        $this->assertNull($workOrder->refresh()->tenant_id);

        $this->artisan('hoa:relink-tenants')
            ->expectsOutputToContain('linked Mark Bridenbeck')
            ->assertSuccessful();

        $this->assertSame($tenant->id, $workOrder->refresh()->tenant_id);
        $this->assertNull($untouched->refresh()->tenant_id);
    }

    public function test_relink_command_reports_work_orders_with_no_lease_tenant(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43900, 'tenant_id' => null]);
        TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);

        $this->artisan('hoa:relink-tenants')
            ->expectsOutputToContain('no lease tenant on record')
            ->assertSuccessful();

        $this->assertNull($workOrder->refresh()->tenant_id);
    }
}
