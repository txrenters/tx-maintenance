<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrderDetailsVendorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_only_receives_their_own_vendor_assignment_on_work_order_details(): void
    {
        Role::query()->create(['name' => 'vendor', 'guard_name' => 'web']);

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $visibleVendorUser = User::factory()->create();
        $visibleVendorUser->assignRole('vendor');

        $visibleVendor = Vendor::query()->create([
            'propertyware_id' => 'V-301',
            'name' => 'Visible Vendor HVAC',
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => $visibleVendorUser->id,
        ]);

        $hiddenVendorUser = User::factory()->create();
        $hiddenVendor = Vendor::query()->create([
            'propertyware_id' => 'V-302',
            'name' => 'Hidden Vendor Electric',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'user_id' => $hiddenVendorUser->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
        ]);

        $workOrder->vendors()->attach([$visibleVendor->id, $hiddenVendor->id]);

        $response = $this->actingAs($visibleVendorUser)
            ->get(route('work_orders.details', $workOrder));

        $response->assertOk()
            ->assertSee('Visible Vendor HVAC')
            ->assertDontSee('Hidden Vendor Electric');
    }
}
