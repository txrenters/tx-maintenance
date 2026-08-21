<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClosedBoardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Collect every work_order_no returned across all service statuses in the
     * deferred `service_status` prop of a partial Inertia reload.
     *
     * @return array<int, int>
     */
    private function returnedWorkOrderNumbers(array $query): array
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('work_orders.index', $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();
    }

    private function createClosedWorkOrder(int $number, array $attributes = []): WorkOrder
    {
        $closedStatus = ServiceStatus::query()->firstOrCreate(
            ['name' => 'Closed'],
            ['description' => 'Closed'],
        );

        return WorkOrder::query()->create([
            'service_status_id' => $closedStatus->id,
            'work_order_no' => $number,
            'category' => 'HVAC Maintenance',
            'type' => 'General',
            'status' => 'Closed',
            ...$attributes,
        ]);
    }

    public function test_search_finds_closed_work_order_without_completed_date(): void
    {
        $workOrder = $this->createClosedWorkOrder(42487, ['completed_date' => null]);

        // Even one closed long ago (stale updated_at) must surface when searched.
        DB::table('work_orders')->where('id', $workOrder->id)
            ->update(['updated_at' => now()->subDays(90)]);

        $numbers = $this->returnedWorkOrderNumbers(['search' => '42487']);

        $this->assertContains(42487, $numbers);
    }

    public function test_recently_created_closed_work_order_without_completed_date_appears_on_board(): void
    {
        $this->createClosedWorkOrder(1001, [
            'completed_date' => null,
            'created_date' => now()->subDays(5),
        ]);

        $numbers = $this->returnedWorkOrderNumbers([]);

        $this->assertContains(1001, $numbers);
    }

    public function test_sync_touched_historical_work_order_without_completed_date_stays_off_the_board(): void
    {
        // A years-old closed work order whose updated_at a bulk PropertyWare
        // sync just refreshed: the window must key off created_date, not
        // updated_at, or every sync floods the board with 1,000+ old cards
        // (2026-08-22).
        $this->createClosedWorkOrder(1002, [
            'completed_date' => null,
            'created_date' => now()->subDays(400),
        ]);

        $numbers = $this->returnedWorkOrderNumbers([]);

        $this->assertNotContains(1002, $numbers);
    }

    public function test_completed_date_still_governs_the_window_when_present(): void
    {
        // Recently touched but completed 45 days ago: the window keys off the
        // completed date, so it stays off the unfiltered board as before.
        $this->createClosedWorkOrder(1003, ['completed_date' => now()->subDays(45)->toDateString()]);

        $numbers = $this->returnedWorkOrderNumbers([]);

        $this->assertNotContains(1003, $numbers);
    }
}
