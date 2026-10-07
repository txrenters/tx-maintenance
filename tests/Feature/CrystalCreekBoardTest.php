<?php

namespace Tests\Feature;

use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Crystal Creek Air work orders show on their own board and on no other.
 */
class CrystalCreekBoardTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $serviceStatus;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        $this->serviceStatus = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('woc');

        return $user;
    }

    /** @return array<int, int> */
    private function boardWorkOrderNumbers(string $routeName, string $component): array
    {
        $response = $this->actingAs($this->staff())->get(route($routeName), [
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

    private function crystalCreekWorkOrder(int $number, string $category = 'HVAC', string $status = 'Open'): WorkOrder
    {
        $customer = OutsideCustomer::factory()->create(['name' => 'Pat Customer', 'phone' => '+15125550100']);

        return WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatus->id,
            'work_order_no' => $number,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'service_request_contact_name' => $customer->name,
            'service_request_contact_phone' => $customer->phone,
            'category' => $category,
            'type' => 'Repair',
            'status' => $status,
            'completed_date' => $status === 'Closed' ? now()->toDateString() : null,
            'total_cost' => $status === 'Closed' ? 150 : null,
        ]);
    }

    private function texasRentersWorkOrder(int $number, string $category = 'HVAC'): WorkOrder
    {
        return WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatus->id,
            'work_order_no' => $number,
            'category' => $category,
            'type' => 'Repair',
            'status' => 'Open',
        ]);
    }

    public function test_the_crystal_creek_board_lists_only_crystal_creek_work_orders(): void
    {
        $this->crystalCreekWorkOrder(7000001);
        $this->texasRentersWorkOrder(44321);

        $this->assertSame([7000001], $this->boardWorkOrderNumbers('work_orders.crystal_creek', 'WorkOrder/CrystalCreek'));
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => (string) random_int(100000000, 999999999),
            'name' => $name,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Earl 10-07: "Crystal Creek Air, LLC" is a PropertyWare vendor on ordinary
     * Texas Renters work orders; the page is for those, and they keep their
     * place on every other board.
     */
    public function test_a_texas_renters_work_order_assigned_to_the_crystal_creek_vendor_is_listed_and_stays_on_its_boards(): void
    {
        $crystalCreekVendor = $this->vendor(Vendor::CRYSTAL_CREEK_NAME);
        $otherVendor = $this->vendor('Austin Plumbing Co');

        $assigned = $this->texasRentersWorkOrder(44313, 'HVAC');
        $assigned->vendors()->attach([$crystalCreekVendor->id, $this->vendor(Vendor::THMP_NAME)->id]);

        $notAssigned = $this->texasRentersWorkOrder(44300, 'HVAC');
        $notAssigned->vendors()->attach([$otherVendor->id]);

        $this->assertSame([44313], $this->boardWorkOrderNumbers('work_orders.crystal_creek', 'WorkOrder/CrystalCreek'));

        $mainBoard = $this->boardWorkOrderNumbers('work_orders.index', 'WorkOrder/Index');
        $this->assertContains(44313, $mainBoard);
        $this->assertContains(44300, $mainBoard);
        $this->assertContains(44313, $this->boardWorkOrderNumbers('work_orders.hvac', 'WorkOrder/Hvac'));

        $this->assertFalse($assigned->fresh()->isCrystalCreek(), 'A Texas Renters work order keeps PropertyWare and its automations');
        $this->assertSame([$assigned->id], WorkOrder::query()->forBoard('crystal_creek')->pluck('id')->all());
    }

    public function test_the_vendor_name_match_tolerates_case_suffix_and_whitespace(): void
    {
        foreach (['CRYSTAL CREEK AIR, LLC', 'Crystal Creek Air', ' Crystal Creek Air, LLC '] as $index => $name) {
            $workOrder = $this->texasRentersWorkOrder(44400 + $index);
            $workOrder->vendors()->attach([$this->vendor($name)->id]);
        }

        $decoy = $this->texasRentersWorkOrder(44499);
        $decoy->vendors()->attach([$this->vendor('Crystal Clear Pools')->id]);

        $this->assertSame([44400, 44401, 44402], collect($this->boardWorkOrderNumbers('work_orders.crystal_creek', 'WorkOrder/CrystalCreek'))->sort()->values()->all());
        $this->assertTrue((new Vendor(['name' => 'crystal creek air']))->isCrystalCreek());
        $this->assertFalse((new Vendor(['name' => 'Crystal Clear Pools']))->isCrystalCreek());
    }

    public function test_the_card_carries_the_customer_and_the_source(): void
    {
        $this->crystalCreekWorkOrder(7000001);

        $response = $this->actingAs($this->staff())->get(route('work_orders.crystal_creek'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/CrystalCreek',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $card = collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->first();

        $this->assertSame(WorkOrder::CRYSTAL_CREEK_SOURCE, $card['source']);
        $this->assertSame('Pat Customer', $card['service_request_contact_name']);
        $this->assertSame('+15125550100', $card['service_request_contact_phone']);
    }

    public function test_every_other_board_leaves_crystal_creek_work_orders_out(): void
    {
        $this->crystalCreekWorkOrder(7000001, 'HVAC');
        $this->crystalCreekWorkOrder(7000002, 'Plumbing');
        $this->texasRentersWorkOrder(44321, 'HVAC');
        $this->texasRentersWorkOrder(44322, 'Plumbing');

        $mainBoard = $this->boardWorkOrderNumbers('work_orders.index', 'WorkOrder/Index');
        $this->assertContains(44321, $mainBoard);
        $this->assertContains(44322, $mainBoard);
        $this->assertNotContains(7000001, $mainBoard);
        $this->assertNotContains(7000002, $mainBoard);
        $this->assertSame([44321], $this->boardWorkOrderNumbers('work_orders.hvac', 'WorkOrder/Hvac'));
        $this->assertNotContains(7000001, $this->boardWorkOrderNumbers('work_orders.easy_fix', 'WorkOrder/EasyFix'));
        $this->assertNotContains(7000002, $this->boardWorkOrderNumbers('work_orders.easy_fix', 'WorkOrder/EasyFix'));
    }

    public function test_a_closed_crystal_creek_work_order_stays_off_the_completed_and_paid_boards(): void
    {
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);
        $this->crystalCreekWorkOrder(7000003, 'HVAC', 'Closed');

        $this->assertNotContains(7000003, $this->boardWorkOrderNumbers('work_orders.closed_work_orders', 'WorkOrder/Close'));
        $this->assertNotContains(7000003, $this->boardWorkOrderNumbers('work_orders.paid', 'WorkOrder/Paid'));
        $this->assertEmpty(WorkOrder::query()->forBoard('closed')->pluck('id'));
        $this->assertEmpty(WorkOrder::query()->forBoard('paid')->pluck('id'));
    }

    public function test_for_board_agrees_with_the_boards(): void
    {
        $crystal = $this->crystalCreekWorkOrder(7000001);
        $texas = $this->texasRentersWorkOrder(44321);

        $this->assertSame([$crystal->id], WorkOrder::query()->forBoard('crystal_creek')->pluck('id')->all());
        $this->assertSame([$texas->id], WorkOrder::query()->forBoard('main')->pluck('id')->all());
        $this->assertSame([$texas->id], WorkOrder::query()->forBoard('hvac')->pluck('id')->all());
        $this->assertContains('crystal_creek', WorkOrder::BOARDS);
    }

    public function test_the_summary_endpoint_knows_the_board(): void
    {
        $this->crystalCreekWorkOrder(7000001);

        $this->actingAs($this->staff())
            ->getJson(route('work_orders.summary', ['board' => 'crystal_creek']))
            ->assertOk()
            ->assertJsonPath('stats.board_label', 'Crystal Creek Air')
            ->assertJsonPath('stats.work_orders.total', 1);
    }

    public function test_a_blank_source_is_not_mistaken_for_crystal_creek(): void
    {
        $imported = WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatus->id,
            'work_order_no' => 44321,
            'source' => null,
            'category' => 'HVAC',
            'status' => 'Open',
        ]);

        $this->assertFalse($imported->isCrystalCreek());
        $this->assertSame([$imported->id], WorkOrder::query()->notCrystalCreek()->pluck('id')->all());
        $this->assertContains(44321, $this->boardWorkOrderNumbers('work_orders.hvac', 'WorkOrder/Hvac'));
    }
}
