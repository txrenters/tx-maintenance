<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkOrderDetails;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PropertyWare frequently reports Closed work orders without a Completed
 * Date, and the importers used to write that null verbatim on every sync.
 * WorkOrder::resolveImportCompletedDate now decides what the importers write:
 * a payload date wins, an existing date is preserved, a work order first seen
 * closing is stamped today, and a historical no-date closed work order is
 * dated by its creation so it ages out of the closed board's 30-day window
 * instead of resurfacing as freshly closed (2026-08-22).
 */
class ImportCompletedDateStampTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        Role::findOrCreate('woc', 'web');

        $user = User::factory()->create();
        $user->assignRole('woc');

        return $user;
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

    private function importPayload(array $overrides = []): void
    {
        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        $payload = $this->soapWorkOrderPayload($overrides);

        $this->mock(PropertyWareService::class, function ($mock) use ($payload) {
            $mock->shouldReceive('getWorkOrderByNumber')
                ->with(42111)
                ->andReturn([$payload]);
        });

        $this->actingAs($this->staffUser())
            ->post(route('work_orders.import'), ['work_order_no' => 42111])
            ->assertSessionHasNoErrors();
    }

    private function existingWorkOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create([
            'propertyware_id' => 991001,
            'work_order_no' => 42111,
            ...$attributes,
        ]);
    }

    public function test_a_work_order_first_seen_closing_is_stamped_today(): void
    {
        $this->existingWorkOrder([
            'status' => 'Open',
            'completed_date' => null,
            'created_date' => now()->subDays(62),
        ]);

        $this->importPayload(['status' => 'Closed']);

        $this->assertSame(
            now()->toDateString(),
            WorkOrder::query()->firstOrFail()->completed_date,
        );
    }

    public function test_an_existing_completed_date_survives_a_dateless_closed_payload(): void
    {
        $this->existingWorkOrder([
            'status' => 'Closed',
            'completed_date' => '2026-08-10',
        ]);

        $this->importPayload(['status' => 'Closed']);

        $this->assertSame('2026-08-10', WorkOrder::query()->firstOrFail()->completed_date);
    }

    public function test_a_payload_completed_date_always_wins(): void
    {
        $this->existingWorkOrder([
            'status' => 'Closed',
            'completed_date' => '2026-08-10',
        ]);

        $this->importPayload(['status' => 'Closed', 'completedDate' => '2026-08-15']);

        $this->assertSame('2026-08-15', WorkOrder::query()->firstOrFail()->completed_date);
    }

    public function test_a_historical_dateless_closed_work_order_is_dated_by_its_creation(): void
    {
        // Already Closed with no date: the sync touch must not make it look
        // freshly closed, so it gets its creation date and ages out of the
        // closed board's 30-day window.
        $this->existingWorkOrder([
            'status' => 'Closed',
            'completed_date' => null,
            'created_date' => now()->subDays(400),
        ]);

        $this->importPayload(['status' => 'Closed', 'createdDate' => now()->subDays(400)->toDateString()]);

        $this->assertSame(
            now()->subDays(400)->toDateString(),
            WorkOrder::query()->firstOrFail()->completed_date,
        );
    }

    public function test_a_reopened_work_order_loses_its_completed_date(): void
    {
        $this->existingWorkOrder([
            'status' => 'Closed',
            'completed_date' => '2026-08-10',
        ]);

        $this->importPayload(['status' => 'Open']);

        $this->assertNull(WorkOrder::query()->firstOrFail()->completed_date);
    }

    public function test_a_new_work_order_arriving_closed_without_a_date_is_dated_by_its_creation(): void
    {
        $this->importPayload(['status' => 'Closed']);

        $this->assertSame(
            now()->subDays(62)->toDateString(),
            WorkOrder::query()->firstOrFail()->completed_date,
        );
    }

    /**
     * The details a getWorkOrder SOAP response always carries, matching the
     * columns SyncWorkOrderDetails writes unconditionally.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function syncJobPayload(array $overrides = []): array
    {
        return array_merge([
            'status' => 'Closed',
            'type' => 'Maintenance',
            'category' => 'Plumbing',
            'description' => 'Leaking kitchen sink',
            'priorityAsInt' => 3,
            'createdDate' => now()->subDays(62)->toDateString(),
        ], $overrides);
    }

    public function test_the_details_sync_job_preserves_an_existing_completed_date(): void
    {
        $workOrder = $this->existingWorkOrder([
            'status' => 'Closed',
            'completed_date' => '2026-08-10',
        ]);

        (new SyncWorkOrderDetails($this->syncJobPayload(), $workOrder->id))->handle();

        $this->assertSame('2026-08-10', $workOrder->fresh()->completed_date);
    }

    public function test_the_details_sync_job_stamps_a_work_order_first_seen_closing(): void
    {
        $workOrder = $this->existingWorkOrder([
            'status' => 'Open',
            'completed_date' => null,
            'created_date' => now()->subDays(62),
        ]);

        (new SyncWorkOrderDetails($this->syncJobPayload(), $workOrder->id))->handle();

        $this->assertSame(now()->toDateString(), $workOrder->fresh()->completed_date);
    }
}
