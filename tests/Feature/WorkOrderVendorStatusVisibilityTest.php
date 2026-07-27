<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrderVendorStatusVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Names of the service-status buckets returned in the deferred `service_status`
     * prop of a partial Inertia reload of the work orders index.
     *
     * @return array<int, string>
     */
    private function returnedStatusNames(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->pluck('name')
            ->all();
    }

    public function test_vendor_does_not_receive_paid_or_closed_status_buckets(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);
        // Canonical seeder spelling ("Followup"); the hide-list must match it exactly.
        ServiceStatus::query()->create(['name' => 'Service Completed - Call Tenant for Followup', 'description' => 'Followup']);

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-501',
            'name' => 'Breasy Landscaping',
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        // Active work order the vendor SHOULD see.
        $openWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 6001,
            'status' => 'Open',
            'category' => 'Landscaping',
            'type' => 'Tree Trimming',
        ]);
        $openWorkOrder->vendors()->attach($vendor->id);

        // Paid work order attached to the vendor -> must NOT appear.
        $paidWorkOrder = WorkOrder::factory()->create([
            'work_order_no' => 6002,
            'status' => 'Closed',
            'total_cost' => 250,
            'completed_date' => now()->subDay(),
            'category' => 'Landscaping',
        ]);
        $paidWorkOrder->vendors()->attach($vendor->id);

        // Closed work order attached to the vendor -> must NOT appear.
        $closedWorkOrder = WorkOrder::factory()->create([
            'work_order_no' => 6003,
            'status' => 'Closed',
            'completed_date' => now()->subDay(),
            'category' => 'Landscaping',
        ]);
        $closedWorkOrder->vendors()->attach($vendor->id);

        $statusNames = $this->returnedStatusNames($vendorUser);

        $this->assertNotContains('Paid', $statusNames);
        $this->assertNotContains('Closed', $statusNames);
        $this->assertNotContains('Service Completed - Call Tenant for Followup', $statusNames);
        $this->assertContains('In Progress', $statusNames);
    }

    public function test_staff_still_receive_paid_and_closed_status_buckets(): void
    {
        Role::findOrCreate('woc', 'web');

        ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);

        $staff = User::factory()->create();
        $staff->assignRole('woc');

        $statusNames = $this->returnedStatusNames($staff);

        $this->assertContains('Paid', $statusNames);
        $this->assertContains('Closed', $statusNames);
    }
}
