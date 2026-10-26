<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Lawn Service board carries each card's building coordinates, which is
 * all the corner mini map and the full map need (John Carlo, 2026-10-27).
 */
class LawnServiceBoardMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...User::STAFF_ROLES, 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_the_lawn_board_carries_building_coordinates(): void
    {
        $status = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $placed = Building::query()->create([
            'propertyware_id' => 7301,
            'name' => '1338 Leadenhall Circle',
            'address' => '1338 Leadenhall Circle',
            'portfolio_id' => 900,
            'latitude' => 29.5512345,
            'longitude' => -95.1234567,
        ]);
        $unplaced = Building::query()->create([
            'propertyware_id' => 7302,
            'name' => '15322 Tonya Drive',
            'address' => '15322 Tonya Drive',
            'portfolio_id' => 900,
        ]);

        WorkOrder::factory()->create([
            'work_order_no' => 44464,
            'service_status_id' => $status->id,
            'building_id' => $placed->propertyware_id,
            'category' => 'Lawn Service',
            'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'work_order_no' => 44318,
            'service_status_id' => $status->id,
            'building_id' => $unplaced->propertyware_id,
            'category' => 'Lawn Service',
            'status' => 'Open',
        ]);

        $user = User::factory()->create()->assignRole('woc');

        // The board is a deferred prop: ask for it the way the page does.
        $response = $this->actingAs($user)->get(route('work_orders.lawn_service'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/LawnCare',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $cards = collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->keyBy('work_order_no');

        $this->assertCount(2, $cards);
        $this->assertEqualsWithDelta(29.5512345, (float) $cards[44464]['building']['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-95.1234567, (float) $cards[44464]['building']['longitude'], 0.0000001);
        $this->assertNull($cards[44318]['building']['latitude']);
        $this->assertNull($cards[44318]['building']['longitude']);
    }
}
