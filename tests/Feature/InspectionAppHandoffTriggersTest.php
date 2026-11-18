<?php

namespace Tests\Feature;

use App\Console\Commands\ImportAllWorkOrdersCommand;
use App\Console\Commands\UpdateWorkOrderStatus;
use App\Jobs\ImportWorkOrderJob;
use App\Jobs\PushWorkOrderToInspectionApp;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every place a vendor lands on a work order hands it to the inspection app
 * when the vendor is one of ours: the board's vendor picker, the
 * PropertyWare import, and the two status syncs. Nothing else is touched.
 */
class InspectionAppHandoffTriggersTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $thmp;

    private Vendor $outside;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.inspection_app.url' => 'https://inspection-api.example.test',
            'services.inspection_app.token' => 'trk_live_abc123def456.secret',
            'services.inspection_app.handoff' => true,
        ]);

        foreach (['admin', 'woc', 'vendor', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        $this->thmp = Vendor::query()->create([
            'propertyware_id' => Vendor::THMP_PROPERTYWARE_ID,
            'name' => Vendor::THMP_NAME,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $this->outside = Vendor::query()->create([
            'propertyware_id' => '111',
            'name' => 'Southwinds Electric LLC',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        Http::fake();
        Queue::fake();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_picking_thmp_on_the_board_hands_the_work_order_off(): void
    {
        $this->mock(PropertyWareService::class, fn ($mock) => $mock->shouldReceive('changeWorkOrderVendors')->once());
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 'PW-1', 'cost_estimate' => 150]);

        $this->actingAs($this->admin())
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$this->thmp->id]])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('inspection_app_handoffs', ['work_order_id' => $workOrder->id, 'reason' => 'vendor_change']);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_picking_an_outside_vendor_on_the_board_hands_nothing_off(): void
    {
        $this->mock(PropertyWareService::class, fn ($mock) => $mock->shouldReceive('changeWorkOrderVendors')->once());
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 'PW-2']);

        $this->actingAs($this->admin())
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$this->outside->id]])
            ->assertSessionHas('success');

        $this->assertDatabaseCount('inspection_app_handoffs', 0);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_picking_thmp_on_a_crystal_creek_work_order_hands_it_off(): void
    {
        $this->mock(PropertyWareService::class, fn ($mock) => $mock->shouldNotReceive('changeWorkOrderVendors'));
        $customer = OutsideCustomer::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'cost_estimate' => 150,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'building_id' => null,
            'tenant_id' => null,
            'propertyware_id' => null,
        ]);

        $this->actingAs($this->admin())
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$this->thmp->id]])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('inspection_app_handoffs', ['work_order_id' => $workOrder->id, 'reason' => 'vendor_change']);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_an_imported_work_order_that_arrives_with_thmp_is_handed_off(): void
    {
        $workOrder = WorkOrder::factory()->create(['cost_estimate' => 150]);

        $job = new ImportWorkOrderJob([]);
        $method = new ReflectionMethod($job, 'processVendors');
        $method->invoke($job, ['vendorIDs' => [Vendor::THMP_PROPERTYWARE_ID]], $workOrder->id, now()->toDateTimeString());

        $this->assertDatabaseHas('inspection_app_handoffs', ['work_order_id' => $workOrder->id, 'reason' => 'import']);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_reimport_with_the_same_vendor_does_not_hand_off_again(): void
    {
        $workOrder = WorkOrder::factory()->create(['cost_estimate' => 150]);
        $job = new ImportWorkOrderJob([]);
        $method = new ReflectionMethod($job, 'processVendors');

        $method->invoke($job, ['vendorIDs' => [Vendor::THMP_PROPERTYWARE_ID]], $workOrder->id, now()->toDateTimeString());
        $method->invoke($job, ['vendorIDs' => [Vendor::THMP_PROPERTYWARE_ID]], $workOrder->id, now()->toDateTimeString());

        $this->assertDatabaseCount('inspection_app_handoffs', 1);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_the_status_sync_hands_off_a_newly_assigned_thmp_but_not_an_existing_one(): void
    {
        $workOrder = WorkOrder::factory()->create(['cost_estimate' => 150]);
        $command = app(UpdateWorkOrderStatus::class);
        $method = new ReflectionMethod($command, 'recordVendorAssignment');

        $method->invoke($command, $workOrder->id, $this->thmp->id);
        $method->invoke($command, $workOrder->id, $this->thmp->id);

        $this->assertDatabaseCount('inspection_app_handoffs', 1);
        $this->assertDatabaseHas('inspection_app_handoffs', ['work_order_id' => $workOrder->id, 'reason' => 'status_sync']);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_the_full_import_hands_off_a_newly_assigned_thmp(): void
    {
        $workOrder = WorkOrder::factory()->create(['cost_estimate' => 150]);
        $command = app(ImportAllWorkOrdersCommand::class);
        $method = new ReflectionMethod($command, 'recordVendorAssignment');

        $method->invoke($command, $workOrder->id, $this->thmp->id);

        $this->assertDatabaseHas('inspection_app_handoffs', ['work_order_id' => $workOrder->id, 'reason' => 'import_all']);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_with_the_switch_off_no_trigger_writes_anything(): void
    {
        config(['services.inspection_app.handoff' => false]);
        $workOrder = WorkOrder::factory()->create(['cost_estimate' => 150]);

        $job = new ImportWorkOrderJob([]);
        (new ReflectionMethod($job, 'processVendors'))->invoke($job, ['vendorIDs' => [Vendor::THMP_PROPERTYWARE_ID]], $workOrder->id, now()->toDateTimeString());

        $this->assertDatabaseHas('work_order_vendors', ['work_order_id' => $workOrder->id, 'vendor_id' => $this->thmp->id]);
        $this->assertDatabaseCount('inspection_app_handoffs', 0);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_the_push_runs_every_five_minutes_in_the_background(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'inspection-app:push-work-orders'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(60, $event->expiresAt);
    }
}
