<?php

namespace Tests\Feature;

use App\Jobs\AdoptCategorizedHoaViolationJob;
use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Services\PropertyWareService;
use App\Services\TenantRequestIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TenantRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'The kitchen faucet has been dripping for three days.';

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New request.']);

        config([
            'services.tenant_portal.create_request_enabled' => true,
            'services.tenant_portal.pw_create_enabled' => true,
            'services.tenant_portal.pw_category' => 'General Maintenance',
            'services.tenant_portal.pw_type' => 'Service Request',
        ]);
    }

    private function building(): Building
    {
        return Building::query()->create([
            'propertyware_id' => 'B-6341DM',
            'name' => 'Del Monte',
            'address' => '6341 Del Monte Dr',
            'portfolio_id' => 900,
        ]);
    }

    private function sourceWorkOrder(array $overrides = []): WorkOrder
    {
        $building = $this->building();

        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);

        return WorkOrder::factory()->create(array_merge([
            'service_status_id' => ServiceStatus::query()->value('id'),
            'work_order_no' => 43361,
            'tenant_id' => $tenant->id,
            'building_id' => $building->propertyware_id,
            'portfolio_id' => 900,
            'location' => 'MULLERJJ | 3235QUARRYPL',
            'unit_id' => '4209311746',
        ], $overrides));
    }

    /**
     * @return MockInterface&PropertyWareService
     */
    private function mockPropertyWare(?string $createdId, ?array $latest = ['location' => 'MULLERJJ | 3235QUARRYPL', 'unitIDs' => [4209311746]])
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getLatestWorkOrderForBuilding')->andReturn($latest);
        $mock->shouldReceive('createWorkOrder')->andReturn($createdId)->byDefault();
        $mock->shouldReceive('getWorkOrder')->andReturn(['number' => 55123])->byDefault();
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([])->byDefault();
        $this->app->instance(PropertyWareService::class, $mock);

        return $mock;
    }

    private function intake(): TenantRequestIntakeService
    {
        return app(TenantRequestIntakeService::class);
    }

    public function test_it_creates_the_request_in_propertyware_with_the_configured_category_and_the_buildings_location(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();

        $mock = $this->mockPropertyWare(null);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->with(Mockery::on(function (array $payload) {
                return $payload['category'] === 'General Maintenance'
                    && $payload['type'] === 'Service Request'
                    && $payload['location'] === 'MULLERJJ | 3235QUARRYPL'
                    && (int) $payload['unit_id'] === 4209311746
                    && $payload['building_id'] === 'B-6341DM'
                    && str_starts_with($payload['description'], self::DESCRIPTION);
            }))
            ->andReturn(null);

        $this->intake()->createForTenant($source, self::DESCRIPTION);
    }

    public function test_the_configured_category_is_never_the_source_work_orders(): void
    {
        Queue::fake();
        // A turnover source would silence every tenant and owner message if its
        // category were copied onto the new request.
        $source = $this->sourceWorkOrder(['type' => 'Turnover', 'category' => 'Make ready']);

        $mock = $this->mockPropertyWare(null);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['category'] === 'General Maintenance' && $payload['type'] === 'Service Request'))
            ->andReturn(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $this->assertSame('General Maintenance', $result['work_order']->category);
        $this->assertSame('Service Request', $result['work_order']->type);
        $this->assertFalse($result['work_order']->skipsAutomatedMessages());
    }

    public function test_the_shipped_propertyware_defaults_are_values_propertyware_actually_issues(): void
    {
        // setUp() overrides these, so read the config file itself.
        $defaults = require config_path('services.php');
        $category = $defaults['tenant_portal']['pw_category'];
        $type = $defaults['tenant_portal']['pw_type'];

        // PropertyWare matches its picklists verbatim. These two are the values
        // it puts on its own work orders in bulk. "Repair" and "Maintenance"
        // read as obvious choices but appear only on rows the test factory
        // made — sending either fails the create silently, exactly the way the
        // HOA category did until 2026-08-04.
        $this->assertSame('General Maintenance', $category);
        $this->assertSame('Service Request', $type);

        // And never a value that would trip skipsAutomatedMessages() or
        // isHoaViolation() and silence the tenant's own confirmation.
        $silencing = ['Turnover', 'Re-Key', 'Cleaning', 'Make ready', 'Carpet Steam Clean', 'HOA Violation'];
        $this->assertNotContains($category, $silencing);
        $this->assertNotContains($type, $silencing);
    }

    public function test_the_category_is_sent_with_the_picklists_exact_spelling(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();

        // PropertyWare's real HVAC entry carries a trailing space, and a
        // visually identical value is rejected.
        WorkOrderCategory::query()->create(['name' => 'HVAC ']);
        config(['services.tenant_portal.pw_category' => 'hvac']);

        $mock = $this->mockPropertyWare(null);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['category'] === 'HVAC '))
            ->andReturn(null);

        $this->intake()->createForTenant($source, self::DESCRIPTION);
    }

    public function test_a_single_unit_returned_as_a_scalar_is_not_sliced_into_one_character(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();

        $mock = $this->mockPropertyWare(null, [
            'location' => 'MULLERJJ | 3235QUARRYPL',
            'unitIDs' => '4209311746',
        ]);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['unit_id'] === '4209311746'))
            ->andReturn(null);

        $this->intake()->createForTenant($source, self::DESCRIPTION);
    }

    public function test_it_falls_back_to_the_source_work_orders_piped_location_when_propertyware_has_no_history(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();

        $mock = $this->mockPropertyWare(null, null);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['location'] === 'MULLERJJ | 3235QUARRYPL'
                && $payload['unit_id'] === '4209311746'))
            ->andReturn(null);

        $this->intake()->createForTenant($source, self::DESCRIPTION);
    }

    public function test_it_refuses_to_send_a_location_propertyware_would_reject(): void
    {
        Queue::fake();
        // The REST importer stores PropertyWare's own location string, without
        // the pipe — sending it fails the whole create with "Location is
        // invalid", so it must never leave the app.
        $source = $this->sourceWorkOrder(['location' => '3235 Quarry Pl', 'unit_id' => null]);

        $mock = $this->mockPropertyWare(null, null);
        $mock->shouldNotReceive('createWorkOrder');

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $this->assertNotNull($result['work_order']);
        $this->assertFalse($result['pw_created']);
        $this->assertNull($result['work_order']->work_order_no);
    }

    public function test_a_failed_propertyware_create_still_captures_the_request_locally_with_its_photos(): void
    {
        Queue::fake();
        Storage::fake('public');
        $source = $this->sourceWorkOrder();
        $this->mockPropertyWare(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION, [
            UploadedFile::fake()->image('leak.jpg'),
        ]);

        $new = $result['work_order'];

        $this->assertNotNull($new);
        $this->assertFalse($result['pw_created']);
        $this->assertSame('Tenant Portal', $new->source);
        $this->assertSame('Open', $new->status);
        $this->assertNotNull($new->service_status_id);
        $this->assertStringStartsWith(self::DESCRIPTION, $new->description);
        $this->assertSame($source->tenant_id, $new->tenant_id);

        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $new->id,
            'type' => 'before',
            'uploaded_via_tenant_portal' => true,
            'is_publish_to_tenant_portal' => true,
        ]);
    }

    public function test_the_imported_request_is_relinked_to_the_source_tenant(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();

        // The create payload carries no requestedByContact, so PropertyWare
        // hands the work order back with no tenant on it at all.
        $imported = WorkOrder::factory()->create([
            'service_status_id' => ServiceStatus::query()->value('id'),
            'propertyware_id' => 987654,
            'work_order_no' => 55123,
            'tenant_id' => null,
            'building_id' => 'B-6341DM',
        ]);

        $mock = $this->mockPropertyWare('987654');
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([]);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $this->assertTrue($result['pw_created']);
        $this->assertSame($imported->id, $result['work_order']->id);
        $this->assertSame($source->tenant_id, $result['work_order']->fresh()->tenant_id);
        $this->assertSame('Tenant Portal', $result['work_order']->fresh()->source);
    }

    public function test_it_dispatches_the_intake_automations_for_the_new_request(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();
        $this->mockPropertyWare(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);
        $newId = $result['work_order']->id;

        Queue::assertPushed(GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $newId && $job->allowAutoAssign === true);
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $newId);
        Queue::assertPushed(SendTenantWorkOrderIntakeEmailJob::class, fn ($job) => $job->workOrderId === $newId);
        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $newId);

        // The configured category is never "HOA Violation", so the HOA adoption
        // job could only ever be a no-op here.
        Queue::assertNotPushed(AdoptCategorizedHoaViolationJob::class);
    }

    public function test_it_does_not_inherit_the_source_work_orders_paused_automations(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder(['paused_automations' => ['tenant', 'owner']]);
        $this->mockPropertyWare(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $this->assertEmpty($result['work_order']->paused_automations ?? []);
    }

    public function test_it_records_the_creation_in_the_activity_log(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder();
        $this->mockPropertyWare(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $activity = Activity::query()
            ->where('event', TenantRequestIntakeService::CREATED_EVENT)
            ->firstOrFail();

        $this->assertSame($source->id, (int) $activity->subject_id);
        $this->assertSame($result['work_order']->id, $activity->properties['work_order_id']);
        $this->assertSame('B-6341DM', $activity->properties['building_id']);
        $this->assertFalse($activity->properties['pw_created']);
    }

    public function test_a_closed_source_work_order_can_still_open_a_new_request(): void
    {
        Queue::fake();
        $source = $this->sourceWorkOrder(['status' => 'Closed']);
        $this->mockPropertyWare(null);

        $result = $this->intake()->createForTenant($source, self::DESCRIPTION);

        $this->assertNotNull($result['work_order']);
        $this->assertSame('Open', $result['work_order']->status);
    }
}
