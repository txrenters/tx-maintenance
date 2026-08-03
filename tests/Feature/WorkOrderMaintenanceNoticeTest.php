<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The work order Details tab shows the property's PropertyWare maintenance
 * block (notice, spending limits, custom fields). Those custom fields carry
 * lockbox and gate codes, so — like the Building page — only admin, WOC and
 * accounting may receive them.
 */
class WorkOrderMaintenanceNoticeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private const MAINTENANCE_KEYS = [
        'maintenance_notice',
        'maintenance_spending_limit_amount',
        'maintenance_spending_limit_time',
        'maintenance_labor_surcharge_amount',
        'maintenance_labor_surcharge_type',
        'custom_fields',
        'details_synced_at',
    ];

    private function makeWorkOrderOnBuildingWithMaintenance(): WorkOrder
    {
        $building = Building::query()->create([
            'propertyware_id' => 'B-7700',
            'name' => '3111 Aspen Hollow Lane',
            'address' => '3111 Aspen Hollow Ln',
            'maintenance_notice' => "HVAC: Vo's A/C 281-781-6875",
            'maintenance_spending_limit_amount' => 250.00,
            'maintenance_spending_limit_time' => 'NO_LIMIT',
            'maintenance_labor_surcharge_amount' => 0,
            'maintenance_labor_surcharge_type' => 'None',
            'custom_fields' => [
                ['definitionID' => '1', 'fieldName' => 'Lockbox Code', 'value' => '4821'],
                ['definitionID' => '2', 'fieldName' => 'Gated Community? Gate Code?', 'value' => '#1234'],
            ],
            'details_synced_at' => now(),
        ]);

        return WorkOrder::factory()->create([
            'work_order_no' => 7700,
            'status' => 'Open',
            'building_id' => $building->propertyware_id,
        ]);
    }

    private function makeVendorUserOn(WorkOrder $workOrder): User
    {
        Role::findOrCreate('vendor', 'web');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-7700',
            'name' => 'Fixit Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $workOrder->vendors()->attach($vendor->id);

        return $vendorUser;
    }

    public function test_staff_receive_the_building_maintenance_details_from_the_data_endpoint(): void
    {
        Role::findOrCreate('woc', 'web');

        $workOrder = $this->makeWorkOrderOnBuildingWithMaintenance();

        $staff = User::factory()->create();
        $staff->assignRole('woc');

        $response = $this->actingAs($staff)->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();

        $this->assertSame("HVAC: Vo's A/C 281-781-6875", $response->json('building.maintenance_notice'));
        $this->assertSame('NO_LIMIT', $response->json('building.maintenance_spending_limit_time'));
        $this->assertSame('Lockbox Code', $response->json('building.custom_fields.0.fieldName'));
    }

    public function test_vendors_do_not_receive_the_building_maintenance_details_from_the_data_endpoint(): void
    {
        $workOrder = $this->makeWorkOrderOnBuildingWithMaintenance();
        $vendorUser = $this->makeVendorUserOn($workOrder);

        $response = $this->actingAs($vendorUser)->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();

        // The building itself still rides along — only the maintenance block is stripped.
        $this->assertSame('3111 Aspen Hollow Lane', $response->json('building.name'));

        foreach (self::MAINTENANCE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('building'));
        }
    }

    public function test_staff_receive_the_building_maintenance_details_on_the_details_page(): void
    {
        Role::findOrCreate('woc', 'web');

        $workOrder = $this->makeWorkOrderOnBuildingWithMaintenance();

        $staff = User::factory()->create();
        $staff->assignRole('woc');

        $response = $this->actingAs($staff)->get(route('work_orders.details', $workOrder->id), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        $this->assertSame(
            "HVAC: Vo's A/C 281-781-6875",
            $response->json('props.workOrder.building.maintenance_notice')
        );
    }

    public function test_vendors_do_not_receive_the_building_maintenance_details_on_the_details_page(): void
    {
        $workOrder = $this->makeWorkOrderOnBuildingWithMaintenance();
        $vendorUser = $this->makeVendorUserOn($workOrder);

        $response = $this->actingAs($vendorUser)->get(route('work_orders.details', $workOrder->id), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        $this->assertSame('3111 Aspen Hollow Lane', $response->json('props.workOrder.building.name'));

        foreach (self::MAINTENANCE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('props.workOrder.building'));
        }
    }
}
