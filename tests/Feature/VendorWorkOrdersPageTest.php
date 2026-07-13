<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorWorkOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetWorkOrderScopeCache();
    }

    protected function tearDown(): void
    {
        // Acting as a vendor above populates WorkOrderScope's static cache; clear
        // it on the way out so the scoped vendor cannot leak into the next test.
        $this->resetWorkOrderScopeCache();

        parent::tearDown();
    }

    /**
     * WorkOrderScope caches the resolved user in a static property; reset it so
     * it cannot leak between tests in the same process.
     */
    private function resetWorkOrderScopeCache(): void
    {
        $cached = new ReflectionProperty(WorkOrderScope::class, 'cachedUser');
        $cached->setAccessible(true);
        $cached->setValue(null, null);
    }

    /**
     * Work order numbers returned in the deferred `workOrders` prop of a partial
     * Inertia reload of the vendor work orders page.
     *
     * @return array<int, int>
     */
    private function returnedWorkOrderNumbers(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
            'X-Inertia-Partial-Data' => 'workOrders',
        ]);

        $response->assertOk();

        return collect($response->json('props.workOrders'))
            ->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();
    }

    private function makeVendorUser(string $propertywareId, string $name): Vendor
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        return Vendor::query()->create([
            'propertyware_id' => $propertywareId,
            'name' => $name,
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    public function test_vendor_sees_only_their_own_visible_work_orders(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);

        $vendor = $this->makeVendorUser('V-501', 'Breasy Landscaping');
        $otherVendor = $this->makeVendorUser('V-502', 'Rival Landscaping');

        // Visible work order the vendor SHOULD see.
        $visible = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7001,
            'status' => 'Open',
        ]);
        $visible->vendors()->attach($vendor->id);

        // Paid work order attached to the vendor -> must NOT appear.
        $paid = WorkOrder::factory()->create([
            'service_status_id' => ServiceStatus::query()->where('name', 'Paid')->value('id'),
            'work_order_no' => 7002,
            'status' => 'Closed',
        ]);
        $paid->vendors()->attach($vendor->id);

        // Another vendor's work order -> must NOT leak in.
        $foreign = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7003,
            'status' => 'Open',
        ]);
        $foreign->vendors()->attach($otherVendor->id);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertContains(7001, $numbers);
        $this->assertNotContains(7002, $numbers);
        $this->assertNotContains(7003, $numbers);
    }

    public function test_filter_options_reflect_only_values_present_in_vendor_work_orders(): void
    {
        Role::findOrCreate('vendor', 'web');

        $inProgress = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);
        // A status the vendor has no work order for — must not appear as an option.
        ServiceStatus::query()->create(['name' => 'Scheduled', 'description' => 'Scheduled']);

        $vendor = $this->makeVendorUser('V-650', 'Breasy Landscaping');

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $inProgress->id,
            'work_order_no' => 6501,
            'status' => 'Open',
            'category' => 'Lawn service',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $response = $this->actingAs($vendor->user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        $statusNames = collect($response->json('props.statuses'))->pluck('name')->all();
        $categories = $response->json('props.categories');

        $this->assertContains('In Progress', $statusNames);
        $this->assertNotContains('Scheduled', $statusNames);
        $this->assertContains('Lawn service', $categories);
    }

    public function test_vendor_does_not_see_work_orders_they_are_not_tagged_on(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-701', 'Breasy Landscaping');

        // The vendor is NOT attached to this work order, but has a task on it.
        // WorkOrderScope would surface it via its task/attachment OR-branch, but
        // the vendor page must show only work orders tagged to the vendor.
        $untagged = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7501,
            'status' => 'Open',
        ]);
        WorkOrderTask::query()->create([
            'description' => 'Stray task',
            'status' => 'pending',
            'work_order_id' => $untagged->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertNotContains(7501, $numbers);
    }

    public function test_each_work_order_carries_the_vendors_own_tasks_for_card_coloring(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-601', 'Breasy Landscaping');
        $otherUser = User::factory()->create();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 8001,
            'status' => 'Open',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $ownTask = WorkOrderTask::query()->create([
            'description' => 'Mow the lawn',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        // A task on the same work order but assigned to someone else must not
        // ride along in the vendor's payload.
        WorkOrderTask::query()->create([
            'description' => 'WOC-only task',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($vendor->user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
            'X-Inertia-Partial-Data' => 'workOrders',
        ]);

        $response->assertOk();

        $returned = collect($response->json('props.workOrders'))
            ->firstWhere('work_order_no', 8001);

        $taskIds = collect($returned['tasks'] ?? [])->pluck('id')->all();

        $this->assertContains($ownTask->id, $taskIds);
        $this->assertCount(1, $taskIds);
    }
}
