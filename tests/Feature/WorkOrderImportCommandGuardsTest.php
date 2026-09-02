<?php

namespace Tests\Feature;

use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * import:work-orders (the 10-minute fast lane) must accept the sparse
 * payloads PropertyWare sends for some work orders — no building block, no
 * category, no "Service Status" custom field — the way the Import Work Order
 * button path (WorkOrderService) already does. Before these guards such a
 * payload failed the insert, was rolled back and retried every run until it
 * aged out of the newest SOAP pages, and the work order never existed here.
 */
class WorkOrderImportCommandGuardsTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $newStatus;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Role::findOrCreate('woc', 'web');
        $this->newStatus = ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
        ServiceStatus::query()->firstOrCreate(['name' => 'Scheduled'], ['description' => 'Scheduled']);
    }

    /**
     * A SOAP getWorkOrders payload the way PropertyWare returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapWorkOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 777001,
            'number' => 4321,
            'building' => ['portfolio' => 'Portfolio A', 'abbreviation' => 'BLDG1', 'ID' => 999001],
            'category' => 'Maintenance',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'Original tenant request.',
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function fakeSoapListing(array $payloads): void
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrders')->andReturn($payloads);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    public function test_a_payload_without_a_building_block_still_imports(): void
    {
        $payload = $this->soapWorkOrderPayload();
        unset($payload['building']);
        $this->fakeSoapListing([$payload]);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertDatabaseHas('work_orders', [
            'propertyware_id' => 777001,
            'work_order_no' => 4321,
            'building_id' => null,
        ]);
        $this->assertSame(' | ', DB::table('work_orders')->where('propertyware_id', 777001)->value('location'));
    }

    public function test_a_payload_without_a_service_status_field_imports_as_new_with_the_intake_automations(): void
    {
        $this->fakeSoapListing([$this->soapWorkOrderPayload(['customFields' => []])]);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $imported = WorkOrder::query()->where('propertyware_id', 777001)->firstOrFail();
        $this->assertSame($this->newStatus->id, $imported->service_status_id);

        Queue::assertPushed(
            GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $imported->id && $job->allowAutoAssign === true
        );
        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $imported->id);
    }

    public function test_an_existing_work_order_re_imported_without_the_field_keeps_its_service_status(): void
    {
        $scheduled = ServiceStatus::query()->where('name', 'Scheduled')->firstOrFail();
        $existing = WorkOrder::factory()->create([
            'propertyware_id' => 777001,
            'service_status_id' => $scheduled->id,
        ]);

        $this->fakeSoapListing([$this->soapWorkOrderPayload(['customFields' => []])]);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertSame($scheduled->id, $existing->fresh()->service_status_id);
        $this->assertDatabaseCount('work_orders', 1);
        Queue::assertNotPushed(GenerateWorkOrderRecommendationJob::class);
    }

    public function test_a_payload_without_a_category_still_imports(): void
    {
        $payload = $this->soapWorkOrderPayload();
        unset($payload['category']);
        $this->fakeSoapListing([$payload]);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertDatabaseHas('work_orders', ['propertyware_id' => 777001, 'category' => null]);
        $this->assertDatabaseCount('work_order_categories', 0);
    }

    public function test_the_guards_do_not_change_a_complete_payload(): void
    {
        $this->fakeSoapListing([$this->soapWorkOrderPayload()]);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertDatabaseHas('work_orders', [
            'propertyware_id' => 777001,
            'location' => 'Portfolio A | BLDG1',
            'building_id' => 999001,
            'category' => 'Maintenance',
            'service_status_id' => $this->newStatus->id,
        ]);
        $this->assertDatabaseHas('work_order_categories', ['name' => 'Maintenance']);
    }
}
