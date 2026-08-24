<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\ServiceStatus;
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

        // Closed natively in PW but with a stale Service Status custom field
        // and no Date Completed — the common real-world shape. Must not count
        // as open.
        WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'zone' => '2',
            'created_date' => '2026-06-01 00:00:00',
            'status' => 'Closed',
        ]);

        $user = User::factory()->create()->assignRole('admin');

        $response = $this->actingAs($user)
            ->getJson("/scheduler/properties/{$building->propertyware_id}")
            ->assertOk()
            ->json();

        $this->assertSame('Panel House', $response['name']);
        $this->assertSame('500 Panel St, Katy, TX 77494', $response['address']);
        $this->assertSame('2', $response['zone']);
        $this->assertSame(1, $response['open_work_orders'], 'Native-Closed PW status must not count as open.');
        $this->assertSame(3, $response['total_work_orders']);
        $this->assertCount(3, $response['work_orders']);
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
        // Create 'New' first so the WorkOrder factory default stays 'New';
        // only work orders explicitly given $waiting are in the queue.
        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $waiting = ServiceStatus::query()->create([
            'name' => 'Assigned - Waiting on Scheduling',
            'description' => 'THMP scheduling queue',
        ]);

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

        // THMP on a COMPLETED work order must not flag the building — the
        // filter means "currently open THMP work", not "THMP ever worked
        // here". Zone '0' keeps this row out of the zone maps.
        $completed = WorkOrder::factory()->create([
            'building_id' => 998812,
            'zone' => '0',
            'completed_date' => '2026-07-01 00:00:00',
        ]);
        $completed->vendors()->attach($thmp->id);

        // Closed natively in PW with a stale waiting Service Status and no
        // Date Completed: THMP is attached, but native-Closed work must count
        // as neither THMP work nor unscheduled work.
        $nativeClosed = WorkOrder::factory()->create([
            'building_id' => 998812,
            'zone' => '0',
            'status' => 'Closed',
            'service_status_id' => $waiting->id,
        ]);
        $nativeClosed->vendors()->attach($thmp->id);

        // The queue: waiting-on-scheduling status AND THMP assigned.
        $unscheduledHouse = Building::query()->create([
            'propertyware_id' => 998815,
            'name' => 'Unscheduled House',
            'address' => '600 Epsilon St',
            'city' => 'Cypress',
            'active' => true,
            'latitude' => 29.96,
            'longitude' => -95.69,
        ]);
        WorkOrder::factory()->create([
            'building_id' => $unscheduledHouse->propertyware_id,
            'zone' => '0',
            'service_status_id' => $waiting->id,
        ])->vendors()->attach($thmp->id);

        // Waiting-on-scheduling status but no THMP: another vendor's problem,
        // not our scheduling queue.
        $otherVendorHouse = Building::query()->create([
            'propertyware_id' => 998816,
            'name' => 'Other Vendor House',
            'address' => '700 Zeta St',
            'city' => 'Cypress',
            'active' => true,
            'latitude' => 29.95,
            'longitude' => -95.68,
        ]);
        WorkOrder::factory()->create([
            'building_id' => $otherVendorHouse->propertyware_id,
            'zone' => '0',
            'service_status_id' => $waiting->id,
        ]);

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

                $this->assertCount(4, $properties, 'Only active geocoded buildings are pins.');

                $own = $properties->firstWhere('name', 'Own Zone House');
                $this->assertSame('4', $own['zone'], 'A building with its own work orders uses its own dominant zone.');
                $this->assertSame('100 Alpha St, Cypress', $own['address']);
                $this->assertEqualsWithDelta(29.97, $own['lat'], 0.000001);

                $inherited = $properties->firstWhere('name', 'City Zone House');
                $this->assertSame('2', $inherited['zone'], 'A building without zoned work orders inherits the city zone.');

                $own = $properties->firstWhere('name', 'Own Zone House');
                $this->assertTrue($own['thmp'], 'A building with an open THMP work order is flagged.');
                $this->assertFalse($inherited['thmp'], 'A completed THMP work order must not flag the building.');

                $this->assertFalse($own['unscheduled'], 'Open THMP work not in the waiting-on-scheduling status is not unscheduled.');
                $this->assertTrue($properties->firstWhere('name', 'Unscheduled House')['unscheduled'], 'Waiting-on-scheduling status with THMP assigned flags the building.');
                $this->assertFalse($inherited['unscheduled'], 'Native-Closed work orders never count, even with the waiting status and THMP.');
                $this->assertFalse(
                    $properties->firstWhere('name', 'Other Vendor House')['unscheduled'],
                    'The waiting status without THMP is not our scheduling queue.'
                );

                $cypress = collect($props['cities'])->firstWhere('name', 'Cypress');
                $this->assertSame(5, $cypress['properties']);
                $this->assertSame(1, $cypress['ungeocoded']);

                return $page->component('Inspection/Scheduler');
            });
    }

    public function test_calendar_visits_are_grouped_by_day_and_matched_to_buildings(): void
    {
        Building::query()->create([
            'propertyware_id' => 998831,
            'name' => 'Visit House',
            'address' => '311 San Julio Dr',
            'city' => 'Houston',
            'active' => true,
            'latitude' => 29.8123,
            'longitude' => -95.4321,
        ]);

        $client = JobberClient::query()->create([
            'jobber_id' => 'client-cal-1',
            'name' => 'Cal Client',
            'jobber_web_uri' => 'https://secure.getjobber.com/clients/1',
        ]);
        // Messy casing and spacing on purpose: address matching must be
        // normalized (squish + lowercase) on both sides.
        $matchedProperty = JobberProperty::query()->create([
            'jobber_id' => 'property-cal-1',
            'jobber_client_id' => $client->id,
            'street' => ' 311  SAN JULIO DR ',
            'city' => 'HOUSTON',
        ]);
        $unmatchedProperty = JobberProperty::query()->create([
            'jobber_id' => 'property-cal-2',
            'jobber_client_id' => $client->id,
            'street' => '999 Nowhere Ln',
            'city' => 'Houston',
        ]);

        $matchedJob = Jobber::query()->create([
            'jobber_id' => 'job-cal-1',
            'job_number' => '19989',
            'title' => 'Zone 2 - Q3 2026 Tenant Benefit Package',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $matchedProperty->id,
        ]);
        $unmatchedJob = Jobber::query()->create([
            'jobber_id' => 'job-cal-2',
            'job_number' => '20001',
            'title' => 'Zone 1 - General Maintenance',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $unmatchedProperty->id,
        ]);

        foreach ([
            ['visit-cal-1', $matchedJob, $matchedProperty, '2026-08-24 09:00:00'],
            ['visit-cal-2', $unmatchedJob, $unmatchedProperty, '2026-08-24 13:00:00'],
            ['visit-cal-3', $matchedJob, $matchedProperty, '2026-09-02 09:00:00'],
        ] as [$gid, $job, $property, $startAt]) {
            JobberVisit::query()->create([
                'jobber_id' => $gid,
                'jobber_job_id' => $job->id,
                'jobber_client_id' => $client->id,
                'jobber_property_id' => $property->id,
                'start_at' => $startAt,
            ]);
        }

        $vendor = User::factory()->create()->assignRole('vendor');
        $this->actingAs($vendor)->getJson('/scheduler/visits?month=2026-08')->assertForbidden();

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->getJson('/scheduler/visits?month=2026-8')->assertStatus(422);

        $response = $this->actingAs($admin)
            ->getJson('/scheduler/visits?month=2026-08')
            ->assertOk()
            ->json();

        $this->assertSame('2026-08', $response['month']);
        $this->assertArrayNotHasKey('2026-09-02', $response['days'], 'Only the requested month is returned.');

        $day = $response['days']['2026-08-24'];
        $this->assertCount(2, $day);
        $this->assertSame('19989', $day[0]['job_number']);
        $this->assertEqualsWithDelta(29.8123, $day[0]['lat'], 0.000001, 'Normalized address match pins the visit.');
        $this->assertNull($day[1]['lat'], 'A visit with no matching building stays unpinned.');
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
