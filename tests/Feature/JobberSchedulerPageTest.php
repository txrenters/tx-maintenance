<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberSchedulerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['admin', 'woc', 'vendor', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/scheduler')->assertRedirect();
    }

    public function test_vendors_and_tenants_are_forbidden(): void
    {
        foreach (['vendor', 'tenant'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)->get('/scheduler')->assertForbidden();
        }
    }

    public function test_admins_and_wocs_see_the_page(): void
    {
        foreach (['admin', 'woc'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)
                ->get('/scheduler')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Inspection/Scheduler')
                    ->where('title', 'Scheduler')
                    ->has('cities')
                    ->has('properties'));
        }
    }

    public function test_property_panel_returns_details_and_work_orders(): void
    {
        $building = Building::query()->create([
            'propertyware_id' => 998821,
            'name' => 'Panel House',
            'address' => '500 Panel St',
            'city' => 'Katy',
            'state_region' => 'TX',
            'postal_code' => '77494',
            'active' => true,
            'latitude' => 29.78,
            'longitude' => -95.82,
        ]);

        $open = WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'zone' => '2',
            'created_date' => '2026-08-10 00:00:00',
        ]);
        WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'zone' => '2',
            'created_date' => '2026-07-01 00:00:00',
            'completed_date' => '2026-07-05 00:00:00',
        ]);

        $user = User::factory()->create()->assignRole('admin');

        $response = $this->actingAs($user)
            ->getJson("/scheduler/properties/{$building->propertyware_id}")
            ->assertOk()
            ->json();

        $this->assertSame('Panel House', $response['name']);
        $this->assertSame('500 Panel St, Katy, TX 77494', $response['address']);
        $this->assertSame('2', $response['zone']);
        $this->assertSame(1, $response['open_work_orders']);
        $this->assertSame(2, $response['total_work_orders']);
        $this->assertCount(2, $response['work_orders']);
        $this->assertSame($open->work_order_no, $response['work_orders'][0]['work_order_no'], 'Newest first.');
        $this->assertSame('2026-08-10', $response['work_orders'][0]['created_date']);
    }

    public function test_property_panel_is_forbidden_for_vendors_and_missing_ids_404(): void
    {
        Building::query()->create([
            'propertyware_id' => 998822,
            'name' => 'Some House',
            'active' => true,
        ]);

        $vendor = User::factory()->create()->assignRole('vendor');
        $this->actingAs($vendor)->getJson('/scheduler/properties/998822')->assertForbidden();

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->getJson('/scheduler/properties/123456789')->assertNotFound();
    }

    public function test_geocoded_properties_are_listed_with_zone_fallback(): void
    {
        $ownZone = Building::query()->create([
            'propertyware_id' => 998811,
            'name' => 'Own Zone House',
            'address' => '100 Alpha St',
            'city' => 'Cypress',
            'active' => true,
            'latitude' => 29.97,
            'longitude' => -95.7,
        ]);
        $ownZoneOrders = WorkOrder::factory()->count(2)->create([
            'building_id' => $ownZone->propertyware_id,
            'zone' => '4',
        ]);

        // Odd casing and whitespace on purpose: the THMP flag must match the
        // same forgiving rule as Vendor::isThmp().
        $thmp = Vendor::query()->create([
            'propertyware_id' => 555001,
            'name' => ' texas home maintenance pros ',
            'user_id' => User::factory()->create()->id,
        ]);
        $ownZoneOrders->first()->vendors()->attach($thmp->id);

        Building::query()->create([
            'propertyware_id' => 998812,
            'name' => 'City Zone House',
            'address' => '200 Beta St',
            'city' => 'Cypress',
            'active' => true,
            'latitude' => 29.98,
            'longitude' => -95.71,
        ]);

        // Ungeocoded, but its work orders make zone 2 the city's dominant zone.
        $ungeocoded = Building::query()->create([
            'propertyware_id' => 998813,
            'name' => 'Ungeocoded House',
            'address' => '300 Gamma St',
            'city' => 'Cypress',
            'active' => true,
        ]);
        WorkOrder::factory()->count(3)->create([
            'building_id' => $ungeocoded->propertyware_id,
            'zone' => '2',
        ]);

        Building::query()->create([
            'propertyware_id' => 998814,
            'name' => 'Inactive House',
            'address' => '400 Delta St',
            'city' => 'Cypress',
            'active' => false,
            'latitude' => 29.99,
            'longitude' => -95.72,
        ]);

        $user = User::factory()->create()->assignRole('admin');

        $this->actingAs($user)
            ->get('/scheduler')
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $properties = collect($props['properties']);

                $this->assertCount(2, $properties, 'Only active geocoded buildings are pins.');

                $own = $properties->firstWhere('name', 'Own Zone House');
                $this->assertSame('4', $own['zone'], 'A building with its own work orders uses its own dominant zone.');
                $this->assertSame('100 Alpha St, Cypress', $own['address']);
                $this->assertEqualsWithDelta(29.97, $own['lat'], 0.000001);

                $inherited = $properties->firstWhere('name', 'City Zone House');
                $this->assertSame('2', $inherited['zone'], 'A building without zoned work orders inherits the city zone.');

                $own = $properties->firstWhere('name', 'Own Zone House');
                $this->assertTrue($own['thmp'], 'A building with a THMP work order is flagged.');
                $this->assertFalse($inherited['thmp'], 'A building without THMP work orders is not flagged.');

                $cypress = collect($props['cities'])->firstWhere('name', 'Cypress');
                $this->assertSame(3, $cypress['properties']);
                $this->assertSame(1, $cypress['ungeocoded']);

                return $page->component('Inspection/Scheduler');
            });
    }

    public function test_coverage_cities_are_aggregated_with_zone_and_coordinates(): void
    {
        $building = Building::query()->create([
            'propertyware_id' => 998801,
            'name' => 'Cypress House A',
            'city' => 'Cypress',
            'state_region' => 'TX',
            'active' => true,
        ]);
        Building::query()->create([
            'propertyware_id' => 998802,
            'name' => 'Cypress House B',
            'city' => 'CYPRESS',
            'state_region' => 'TX',
            'active' => true,
        ]);
        Building::query()->create([
            'propertyware_id' => 998803,
            'name' => 'Old House',
            'city' => 'Inactive Town',
            'state_region' => 'TX',
            'active' => false,
        ]);

        WorkOrder::factory()->count(2)->create([
            'building_id' => $building->propertyware_id,
            'zone' => '2',
        ]);
        WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'zone' => '0',
        ]);

        $user = User::factory()->create()->assignRole('admin');

        $this->actingAs($user)
            ->get('/scheduler')
            ->assertInertia(function (Assert $page) {
                $cities = collect($page->toArray()['props']['cities']);

                $cypress = $cities->firstWhere('name', 'Cypress');
                $this->assertNotNull($cypress);
                $this->assertSame(2, $cypress['properties'], 'City casing variants must be merged.');
                $this->assertSame('2', $cypress['zone'], 'Zone 0 is noise and must not win.');
                $this->assertNotNull($cypress['lat']);
                $this->assertNotNull($cypress['lng']);

                $this->assertNull($cities->firstWhere('name', 'Inactive Town'), 'Inactive buildings are not coverage.');

                return $page->component('Inspection/Scheduler');
            });
    }
}
