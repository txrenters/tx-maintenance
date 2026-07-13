<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkOrderScope;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardVendorScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWorkOrderScopeCache();
    }

    protected function tearDown(): void
    {
        $this->resetWorkOrderScopeCache();
        parent::tearDown();
    }

    private function resetWorkOrderScopeCache(): void
    {
        $cached = new ReflectionProperty(WorkOrderScope::class, 'cachedUser');
        $cached->setAccessible(true);
        $cached->setValue(null, null);
    }

    public function test_dashboard_stats_count_only_work_orders_the_vendor_is_tagged_on(): void
    {
        Role::findOrCreate('vendor', 'web');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-800',
            'name' => 'Breasy Landscaping',
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        // Tagged work order -> should be counted.
        $tagged = WorkOrder::factory()->create(['work_order_no' => 8801, 'status' => 'Open']);
        $tagged->vendors()->attach($vendor->id);

        // NOT tagged, but the vendor has a task on it. WorkOrderScope would
        // surface it via its task OR-branch; the dashboard must not count it.
        $untagged = WorkOrder::factory()->create(['work_order_no' => 8802, 'status' => 'Open']);
        WorkOrderTask::query()->create([
            'description' => 'Stray task',
            'status' => 'pending',
            'work_order_id' => $untagged->id,
            'assigned_user_id' => $vendorUser->id,
        ]);

        $response = $this->actingAs($vendorUser)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        $this->assertSame(1, (int) $response->json('props.stats.total_work_orders'));
    }
}
