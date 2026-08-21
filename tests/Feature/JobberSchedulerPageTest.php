<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
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
                    ->has('cities'));
        }
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
