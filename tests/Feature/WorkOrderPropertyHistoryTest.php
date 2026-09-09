<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Recommendation tab's "Previous Work Orders at This Property" card: every
 * other work order at the same building, newest first, carried inside the
 * recommendation payload.
 */
class WorkOrderPropertyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const BUILDING = 7777;

    private function staff(): User
    {
        Role::findOrCreate('woc', 'web');

        return User::factory()->create()->assignRole('woc');
    }

    private function serviceStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    /**
     * A work order at the building, created $daysAgo days ago.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function job(int $daysAgo, array $overrides = [], ?int $buildingId = self::BUILDING): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'service_status_id' => $this->serviceStatus()->id,
            'building_id' => $buildingId,
            'created_date' => now()->subDays($daysAgo),
        ], $overrides));
    }

    /**
     * The work order being looked at, with a stored recommendation so the show
     * endpoint has a payload to carry the history in.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function current(array $overrides = [], ?int $buildingId = self::BUILDING): WorkOrder
    {
        $workOrder = $this->job(0, $overrides, $buildingId);
        WorkOrderRecommendation::query()->create(['work_order_id' => $workOrder->id]);

        return $workOrder;
    }

    /**
     * @return array<string, mixed>
     */
    private function historyFor(WorkOrder $workOrder, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->staff())
            ->get(route('work_orders.recommendation.show', $workOrder))
            ->assertOk()
            ->json('recommendation.property_history');
    }

    public function test_the_property_s_other_work_orders_are_listed_newest_first_without_the_current_one(): void
    {
        $oldest = $this->job(30, ['status' => 'Closed', 'completed_date' => now()->subDays(25)]);
        $newest = $this->job(2, ['status' => 'Open']);
        $middle = $this->job(10);
        $elsewhere = $this->job(1, [], 8888);
        $current = $this->current();

        $history = $this->historyFor($current);

        $listed = array_column($history['items'], 'id');
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $listed);
        $this->assertSame(3, $history['total']);
        $this->assertNotContains($current->id, $listed);
        $this->assertNotContains($elsewhere->id, $listed);
        $this->assertSame(['Open', 'Open', 'Closed'], array_column($history['items'], 'status'));
        $this->assertEquals(self::BUILDING, $history['property_id']);
        $this->assertNull($history['building'], 'No Building row is synced for this PropertyWare id.');
    }

    public function test_each_row_carries_the_details_a_coordinator_needs(): void
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $prior = $this->job(2, [
            'work_order_no' => 43001,
            'status' => 'Closed',
            'completed_date' => now()->subDays(1),
            'category' => 'Plumbing',
            'type' => 'Repair',
            'priority' => 'High',
            'description' => 'Kitchen sink was clogged, cleared the drain.',
            'is_emergency' => true,
        ]);
        $prior->vendors()->attach($vendor->id);

        $history = $this->historyFor($this->current());

        $this->assertCount(1, $history['items']);
        $item = $history['items'][0];
        $this->assertSame($prior->id, $item['id']);
        $this->assertEquals(43001, $item['work_order_no']);
        $this->assertSame('Closed', $item['status']);
        $this->assertSame('New', $item['service_status']);
        $this->assertSame('Plumbing', $item['category']);
        $this->assertSame('Repair', $item['type']);
        $this->assertSame('High', $item['priority']);
        $this->assertSame('Kitchen sink was clogged, cleared the drain.', $item['description']);
        $this->assertSame('Reliable Plumbing', $item['vendor_names']);
        $this->assertTrue($item['is_emergency']);
        $this->assertSame(now()->subDays(2)->toDateString(), $item['created_date']);
        $this->assertSame(now()->subDays(2)->format('M j, Y'), $item['created_date_label']);
        $this->assertSame(now()->subDays(1)->toDateString(), $item['completed_date']);
        $this->assertSame(now()->subDays(1)->format('M j, Y'), $item['completed_date_label']);
    }

    public function test_only_the_ten_most_recent_are_listed_but_the_total_counts_them_all(): void
    {
        foreach (range(1, 12) as $daysAgo) {
            $this->job($daysAgo);
        }

        $history = $this->historyFor($this->current());

        $this->assertCount(10, $history['items']);
        $this->assertSame(12, $history['total']);
        $this->assertSame(10, $history['limit']);
    }

    public function test_a_work_order_that_is_not_linked_to_a_property_has_no_history(): void
    {
        $this->job(3, [], null);
        $this->job(4);

        $history = $this->historyFor($this->current([], null));

        $this->assertSame(0, $history['total']);
        $this->assertSame([], $history['items']);
        $this->assertNull($history['property_id']);
        $this->assertNull($history['building']);
    }

    public function test_the_property_page_is_linked_when_the_building_is_on_file(): void
    {
        $building = Building::query()->create([
            'propertyware_id' => self::BUILDING,
            'name' => '7777 Sample St',
            'address' => '7777 Sample St',
            'city' => 'Houston',
            'state_region' => 'TX',
        ]);

        $history = $this->historyFor($this->current());

        $this->assertSame(['id' => $building->id, 'name' => '7777 Sample St'], $history['building']);
    }

    public function test_generating_a_recommendation_returns_the_history_too(): void
    {
        $prior = $this->job(5);
        $current = $this->job(0, ['description' => 'Kitchen sink is clogged.', 'is_emergency' => null]);

        $this->actingAs($this->staff())
            ->post(route('work_orders.recommendation.generate', $current))
            ->assertOk()
            ->assertJsonPath('recommendation.property_history.total', 1)
            ->assertJsonPath('recommendation.property_history.items.0.id', $prior->id);
    }

    public function test_the_show_endpoint_still_returns_nothing_before_a_recommendation_exists(): void
    {
        $this->job(5);
        $current = $this->job(0);

        $this->actingAs($this->staff())
            ->get(route('work_orders.recommendation.show', $current))
            ->assertOk()
            ->assertJsonPath('recommendation', null);
    }

    public function test_a_vendor_only_sees_the_property_work_orders_they_are_tagged_on(): void
    {
        Role::findOrCreate('vendor', 'web');
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-800',
            'name' => 'Breasy Landscaping',
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $theirs = $this->job(3);
        $theirs->vendors()->attach($vendor->id);
        $someoneElses = $this->job(2);
        $current = $this->current();
        $current->vendors()->attach($vendor->id);

        $history = $this->historyFor($current, $vendorUser);

        $listed = array_column($history['items'], 'id');
        $this->assertSame([$theirs->id], $listed);
        $this->assertSame(1, $history['total']);
        $this->assertNotContains($someoneElses->id, $listed);
    }

    public function test_a_tenant_only_sees_their_own_work_orders_at_the_property(): void
    {
        Role::findOrCreate('tenant', 'web');
        $tenantUser = User::factory()->create()->assignRole('tenant');
        $tenant = Tenants::factory()->create(['user_id' => $tenantUser->id]);

        $theirs = $this->job(3, ['tenant_id' => $tenant->id]);
        $neighbours = $this->job(2);
        $current = $this->current(['tenant_id' => $tenant->id]);

        $history = $this->historyFor($current, $tenantUser);

        $listed = array_column($history['items'], 'id');
        $this->assertSame([$theirs->id], $listed);
        $this->assertSame(1, $history['total']);
        $this->assertNotContains($neighbours->id, $listed);
    }
}
