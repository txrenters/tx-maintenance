<?php

namespace Tests\Feature;

use App\Jobs\AdoptCategorizedHoaViolationJob;
use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The board "Import Work Order" button: POST /work_orders/import fetches one
 * work order from PropertyWare by number and runs it through
 * WorkOrderService. The import must surface real failures (the button used to
 * flash success no matter what) and behave like the scheduled importer.
 */
class WorkOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        Role::findOrCreate('woc', 'web');

        $user = User::factory()->create();
        $user->assignRole('woc');

        return $user;
    }

    private function serviceStatus(string $name): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => $name],
            ['description' => $name],
        );
    }

    /**
     * A minimal SOAP getWorkOrders payload the way PropertyWare returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapWorkOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 991001,
            'number' => 42111,
            'status' => 'Open',
            'type' => 'Maintenance',
            'category' => 'Plumbing',
            'description' => 'Leaking kitchen sink',
            'priorityAsInt' => 3,
            'createdDate' => now()->subDays(62)->toDateString(),
            'building' => [
                'ID' => 5001,
                'portfolio' => 'Portfolio A',
                'abbreviation' => 'PA-01',
            ],
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
        ], $overrides);
    }

    private function fakePropertyWare(mixed $result, int $workOrderNo = 42111): void
    {
        $this->mock(PropertyWareService::class, function ($mock) use ($result, $workOrderNo) {
            $mock->shouldReceive('getWorkOrderByNumber')
                ->with($workOrderNo)
                ->andReturn($result);
        });
    }

    public function test_a_work_order_can_be_imported_by_number(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload()]);

        $response = $this->actingAs($this->staffUser())
            ->from(route('work_orders.index'))
            ->post(route('work_orders.import'), ['work_order_no' => 42111]);

        $response->assertRedirect(route('work_orders.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_orders', [
            'propertyware_id' => 991001,
            'work_order_no' => 42111,
            'status' => 'Open',
            'type' => 'Maintenance',
        ]);
    }

    public function test_the_type_is_trimmed_on_import(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload(['type' => 'HVAC '])]);

        $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        // Raw column read: the get-mutator would hide an untrimmed value.
        $this->assertSame('HVAC', DB::table('work_orders')->where('propertyware_id', 991001)->value('type'));
    }

    public function test_a_number_unknown_to_propertyware_reports_an_error(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare([]);

        $response = $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111]);

        $response->assertSessionHasErrors('work_order_no')
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_a_propertyware_outage_reports_an_error_instead_of_crashing(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare('Error: could not connect to host');

        $response = $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111]);

        $response->assertSessionHasErrors('work_order_no')
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_a_payload_without_a_service_status_still_imports(): void
    {
        $newStatus = $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload(['customFields' => []])]);

        $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_orders', [
            'propertyware_id' => 991001,
            'service_status_id' => $newStatus->id,
        ]);
    }

    public function test_reimporting_keeps_the_original_created_at(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload()]);
        $staff = $this->staffUser();

        $firstImportAt = Carbon::parse('2026-06-01 10:00:00');
        Carbon::setTestNow($firstImportAt);
        $this->actingAs($staff)->post(route('work_orders.import'), ['work_order_no' => 42111]);

        Carbon::setTestNow('2026-08-05 09:00:00');
        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        Carbon::setTestNow();

        $this->assertDatabaseCount('work_orders', 1);
        $workOrder = WorkOrder::query()->firstOrFail();
        $this->assertTrue($workOrder->created_at->equalTo($firstImportAt));
    }

    public function test_a_new_work_order_triggers_the_intake_automations(): void
    {
        Queue::fake();
        $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload()]);

        $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        $workOrder = WorkOrder::query()->firstOrFail();

        Queue::assertPushed(
            GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $workOrder->id && $job->allowAutoAssign === true
        );
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(AdoptCategorizedHoaViolationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendTenantWorkOrderIntakeEmailJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
    }

    public function test_reimporting_an_existing_work_order_triggers_nothing(): void
    {
        $this->serviceStatus('New');
        $this->fakePropertyWare([$this->soapWorkOrderPayload()]);
        $staff = $this->staffUser();

        $this->actingAs($staff)->post(route('work_orders.import'), ['work_order_no' => 42111]);

        Queue::fake();
        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_the_payload_vendor_is_attached_even_when_busy_on_another_work_order(): void
    {
        $this->serviceStatus('New');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Ace Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        // The old import skipped the vendor when it was attached to ANY work
        // order; being busy elsewhere must not block this attachment.
        $other = WorkOrder::factory()->create();
        $other->vendors()->attach($vendor->id);

        $this->fakePropertyWare([$this->soapWorkOrderPayload(['vendorIDs' => ['V-900']])]);
        $staff = $this->staffUser();

        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();

        $imported = WorkOrder::query()->where('propertyware_id', 991001)->firstOrFail();

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $imported->id,
            'vendor_id' => $vendor->id,
        ]);

        // Re-importing must not duplicate the attachment.
        $this->actingAs($staff)->post(route('work_orders.import'), ['work_order_no' => 42111]);

        $this->assertSame(1, DB::table('work_order_vendors')
            ->where('work_order_id', $imported->id)
            ->where('vendor_id', $vendor->id)
            ->count());
    }

    public function test_a_blank_type_work_order_shows_on_the_main_board(): void
    {
        $this->serviceStatus('New');

        $blank = WorkOrder::factory()->create(['status' => 'Open', 'type' => null, 'category' => null]);
        $turnover = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Turnover']);

        $mainBoardIds = WorkOrder::query()->forBoard('main')->pluck('id')->all();

        $this->assertContains($blank->id, $mainBoardIds);
        $this->assertNotContains($turnover->id, $mainBoardIds);
    }

    public function test_a_blank_type_work_order_renders_on_the_main_board_page(): void
    {
        $this->serviceStatus('New');

        $blank = WorkOrder::factory()->create(['status' => 'Open', 'type' => null, 'category' => null]);

        // service_status is a deferred prop, so ask for it explicitly.
        $response = $this->actingAs($this->staffUser())->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $rendered = collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => collect($status['work_orders'] ?? [])->pluck('id'))
            ->all();

        $this->assertContains($blank->id, $rendered);
    }

    public function test_reimporting_keeps_the_tenant_portal_source_the_app_stamped(): void
    {
        $this->serviceStatus('New');
        $staff = $this->staffUser();

        // PropertyWare reports the app's own API-created work orders with
        // Source "None"; one mock serves the three imports in order.
        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('getWorkOrderByNumber')
                ->with(42111)
                ->andReturn(
                    [$this->soapWorkOrderPayload(['source' => 'None'])],
                    [$this->soapWorkOrderPayload(['source' => 'None'])],
                    [$this->soapWorkOrderPayload(['source' => 'Telephone'])],
                );
        });

        $this->actingAs($staff)->post(route('work_orders.import'), ['work_order_no' => 42111]);
        $this->assertSame('None', WorkOrder::query()->firstOrFail()->source);

        // The tenant portal intake stamps its own rows after the import.
        WorkOrder::query()->update(['source' => 'Tenant Portal']);

        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();
        $this->assertSame('Tenant Portal', WorkOrder::query()->firstOrFail()->source);

        // A real Source PropertyWare later shows still wins.
        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();
        $this->assertSame('Telephone', WorkOrder::query()->firstOrFail()->source);
    }
}
