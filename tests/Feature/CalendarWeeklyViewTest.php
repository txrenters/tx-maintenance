<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceSchedule;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarWeeklyViewTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(string $date, array $overrides = []): ServiceSchedule
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 5001]);
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1, 100000),
            'name' => 'Cool Air HVAC',
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        return ServiceSchedule::create(array_merge([
            'title' => 'AC inspection',
            'status' => 'scheduled',
            'scheduled_date' => $date,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ], $overrides));
    }

    public function test_calendar_renders_the_weekly_card_view_for_a_given_week(): void
    {
        $user = User::factory()->create();

        // Sunday of the target week, plus a schedule mid-week.
        $weekStart = Carbon::create(2026, 7, 12, 0, 0, 0, 'America/Chicago'); // a Sunday
        $this->schedule('2026-07-15');

        $this->actingAs($user)
            ->get(route('scheduled_service', ['week_start' => $weekStart->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->where('weekStart', '2026-07-12')
                ->where('currentWeekRange.start', '2026-07-12')
                ->where('currentWeekRange.end', '2026-07-18')
                ->has('events', 1)
                ->where('events.0.work_order_no', 5001)
                ->where('events.0.title', 'AC inspection')
            );
    }

    public function test_calendar_excludes_schedules_outside_the_visible_week(): void
    {
        $user = User::factory()->create();

        // Schedule falls in the following week — must not appear for this one.
        $this->schedule('2026-07-22');

        $this->actingAs($user)
            ->get(route('scheduled_service', ['week_start' => '2026-07-12']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->has('events', 0)
            );
    }

    public function test_card_location_uses_the_building_street_address(): void
    {
        $user = User::factory()->create();

        // Building linked to the work order via building_id -> propertyware_id.
        $building = Building::query()->create([
            'propertyware_id' => 998877,
            'name' => 'Maple Court',
            'address' => '2927 Burning Tree Ln',
            'city' => 'Houston',
            'state_region' => 'TX',
            'postal_code' => '77339',
        ]);

        $schedule = $this->schedule('2026-07-15');
        $schedule->work_order->update([
            'building_id' => $building->propertyware_id,
            'location' => 'HOWARD,RUSSE | 2927BURNINGT',
        ]);

        $this->actingAs($user)
            ->get(route('scheduled_service', ['week_start' => '2026-07-12']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->has('events', 1)
                ->where('events.0.location', '2927 Burning Tree Ln, Houston, TX, 77339')
            );
    }

    public function test_calendar_filters_the_week_by_search_term(): void
    {
        $user = User::factory()->create();

        $this->schedule('2026-07-14', ['title' => 'Furnace tune-up']);
        $this->schedule('2026-07-16', ['title' => 'Roof leak repair']);

        $this->actingAs($user)
            ->get(route('scheduled_service', ['week_start' => '2026-07-12', 'search' => 'Furnace']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->where('filters.search', 'Furnace')
                ->has('events', 1)
                ->where('events.0.title', 'Furnace tune-up')
            );
    }
}
