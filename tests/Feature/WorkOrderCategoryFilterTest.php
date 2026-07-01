<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Collect every work_order_no returned across all service statuses in the
     * deferred `service_status` prop of a partial Inertia reload.
     *
     * @return array<int, int>
     */
    private function returnedWorkOrderNumbers(string $routeName, string $component, array $query): array
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route($routeName, $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();
    }

    public function test_index_filters_open_work_orders_by_category(): void
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'In Progress',
            'description' => 'In progress',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 1001,
            'category' => 'Plumbing',
            'type' => 'Repair',
            'status' => 'Open',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 1002,
            'category' => 'Electrical',
            'type' => 'Repair',
            'status' => 'Open',
        ]);

        // With the category filter, only the matching work order comes back.
        $filtered = $this->returnedWorkOrderNumbers('work_orders.index', 'WorkOrder/Index', ['category' => 'Plumbing']);

        $this->assertContains(1001, $filtered);
        $this->assertNotContains(1002, $filtered);

        // Without the filter, both open work orders are present.
        $unfiltered = $this->returnedWorkOrderNumbers('work_orders.index', 'WorkOrder/Index', []);

        $this->assertContains(1001, $unfiltered);
        $this->assertContains(1002, $unfiltered);
    }
}
