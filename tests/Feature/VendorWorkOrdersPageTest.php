<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
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
}
