<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BoardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HvacBoardTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $serviceStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceStatus = ServiceStatus::query()->create([
            'name' => 'Assigned - Waiting on Scheduling',
            'description' => 'Waiting on scheduling',
        ]);
    }

    /**
     * Collect every work_order_no the board returns across all its service
     * statuses, via a partial Inertia reload of the deferred prop.
     *
     * @return array<int, int>
     */
    private function boardWorkOrderNumbers(string $routeName, string $component, array $query = []): array
    {
        $response = $this->actingAs(User::factory()->create())->get(route($routeName, $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();
    }

    /** @return array<int, int> */
    private function hvacBoardWorkOrderNumbers(array $query = []): array
    {
        return $this->boardWorkOrderNumbers('work_orders.hvac', 'WorkOrder/Hvac', $query);
    }

    private function makeWorkOrder(int $number, ?string $category, string $type = 'Service Request', string $status = 'Open'): WorkOrder
    {
        return WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatus->id,
            'work_order_no' => $number,
            'category' => $category,
            'type' => $type,
            'status' => $status,
        ]);
    }

    /**
     * The whole reason this board matches with LIKE. PropertyWare's real
     * picklist value is "HVAC " with a trailing space and it is what the vast
     * majority of work orders carry; an exact comparison would drop them all.
     */
    public function test_board_includes_the_trailing_space_spelling(): void
    {
        $this->makeWorkOrder(2001, 'HVAC ');

        $this->assertContains(2001, $this->hvacBoardWorkOrderNumbers());
    }

    public function test_board_includes_hvac_carried_on_the_type_with_no_category(): void
    {
        $this->makeWorkOrder(2002, null, 'HVAC Maintenance');

        $this->assertContains(2002, $this->hvacBoardWorkOrderNumbers());
    }

    public function test_board_includes_the_other_heating_and_cooling_spellings(): void
    {
        $this->makeWorkOrder(2003, 'HVAC');
        $this->makeWorkOrder(2004, 'HVAC Fan');
        $this->makeWorkOrder(2005, 'AC Filter Delivery');
        $this->makeWorkOrder(2006, 'Thermostat');
        $this->makeWorkOrder(2007, 'Central Heating');
        $this->makeWorkOrder(2008, 'Heater');

        $returned = $this->hvacBoardWorkOrderNumbers();

        foreach ([2003, 2004, 2005, 2006, 2007, 2008] as $number) {
            $this->assertContains($number, $returned);
        }
    }

    /**
     * Water heaters are plumbing. They match the %heater% term, so the scope
     * excludes them explicitly and this pins that decision.
     */
    public function test_board_excludes_water_heaters(): void
    {
        $this->makeWorkOrder(2009, 'Water heater');

        $this->assertNotContains(2009, $this->hvacBoardWorkOrderNumbers());
    }

    public function test_board_excludes_unrelated_trades(): void
    {
        $this->makeWorkOrder(2010, 'Plumbing');
        $this->makeWorkOrder(2011, 'Electrical');

        $returned = $this->hvacBoardWorkOrderNumbers();

        $this->assertNotContains(2010, $returned);
        $this->assertNotContains(2011, $returned);
    }

    public function test_board_leaves_closed_hvac_out_of_the_open_columns(): void
    {
        $this->makeWorkOrder(2012, 'HVAC ', 'Service Request', 'Closed');

        $this->assertNotContains(2012, $this->hvacBoardWorkOrderNumbers());
    }

    /**
     * HVAC work orders deliberately stay on the main board as well, the way HOA
     * violations do, so this board is an extra view rather than a move. Without
     * this test a later change could quietly take them off Active.
     */
    public function test_hvac_work_orders_still_appear_on_the_main_board(): void
    {
        $this->makeWorkOrder(2013, 'HVAC ');

        $this->assertContains(
            2013,
            $this->boardWorkOrderNumbers('work_orders.index', 'WorkOrder/Index'),
        );
    }

    public function test_board_applies_the_shared_work_order_number_search(): void
    {
        $this->makeWorkOrder(2014, 'HVAC ');
        $this->makeWorkOrder(2015, 'HVAC ');

        $returned = $this->hvacBoardWorkOrderNumbers(['search' => 2014]);

        $this->assertContains(2014, $returned);
        $this->assertNotContains(2015, $returned);
    }

    /**
     * The board is registered for the AI summary. scopeForBoard('hvac') has to
     * agree with the controller's predicate or the summary would describe a
     * different set of work orders than the cards show.
     */
    public function test_hvac_is_registered_for_the_board_summary(): void
    {
        $this->assertContains('hvac', WorkOrder::BOARDS);
        $this->assertSame('HVAC', BoardSummaryService::label('hvac'));

        $this->makeWorkOrder(2016, 'HVAC ');
        $this->makeWorkOrder(2017, 'Water heater');
        $this->makeWorkOrder(2018, 'Plumbing');

        $summarized = WorkOrder::query()->forBoard('hvac')->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();

        $this->assertContains(2016, $summarized);
        $this->assertNotContains(2017, $summarized);
        $this->assertNotContains(2018, $summarized);
    }
}
