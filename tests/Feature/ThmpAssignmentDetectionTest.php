<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Jobber job dispatch decides "is THMP assigned?" with a DB query; it must
 * use the same trimmed, case-insensitive rule as Vendor::isThmp() so a vendor
 * record with stray whitespace or odd casing still auto-syncs to Jobber.
 */
class ThmpAssignmentDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $name): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_thmp_is_detected_despite_whitespace_and_casing(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->makeVendor('  texas HOME maintenance pros  ')->workOrders()->attach($workOrder->id);

        $this->assertTrue(Vendor::isThmpAssignedToWorkOrder($workOrder->id));
    }

    public function test_the_exact_thmp_name_is_detected(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->makeVendor(Vendor::THMP_NAME)->workOrders()->attach($workOrder->id);

        $this->assertTrue(Vendor::isThmpAssignedToWorkOrder($workOrder->id));
    }

    public function test_a_third_party_vendor_does_not_trigger_the_jobber_dispatch(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->makeVendor('Reliable Plumbing')->workOrders()->attach($workOrder->id);

        $this->assertFalse(Vendor::isThmpAssignedToWorkOrder($workOrder->id));
    }

    public function test_a_work_order_with_no_vendors_does_not_match(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->assertFalse(Vendor::isThmpAssignedToWorkOrder($workOrder->id));
    }
}
