<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The boards' Vendor filter when the selected vendor is THMP.
 *
 * Staff tag THMP onto Jimmie Gendke SFA's work orders only so the app creates
 * the Jobber job; those work orders are SFA's and must stay off THMP's queue,
 * on the boards and in the Summary popup alike. Every other vendor, and the
 * unfiltered board, are unchanged.
 */
class WorkOrderVendorFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private ServiceStatus $status;

    private Vendor $thmp;

    /** A second row named THMP, with stray whitespace and casing. */
    private Vendor $thmpTwin;

    private Vendor $jimmie;

    /** A different SFA vendor that a LIKE '%SFA%' rule would wrongly catch. */
    private Vendor $sfaMichael;

    private Vendor $ace;

    private int $vendorSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        $this->staff = User::factory()->create();
        $this->staff->assignRole('woc');

        $this->status = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $this->thmp = $this->vendor(Vendor::THMP_NAME);
        $this->thmpTwin = $this->vendor(' texas home maintenance pros ');
        $this->jimmie = $this->vendor('Jimmie Gendke SFA');
        $this->sfaMichael = $this->vendor('SFA - Michael');
        $this->ace = $this->vendor('Ace Plumbing');

        // 1001 THMP only; 1002 THMP + Jimmie; 1003 THMP + Jimmie + Ace;
        // 1004 THMP + SFA - Michael; 1005 twin only; 1006 twin + Jimmie.
        $this->workOrder(1001, [$this->thmp]);
        $this->workOrder(1002, [$this->thmp, $this->jimmie]);
        $this->workOrder(1003, [$this->thmp, $this->jimmie, $this->ace]);
        $this->workOrder(1004, [$this->thmp, $this->sfaMichael]);
        $this->workOrder(1005, [$this->thmpTwin]);
        $this->workOrder(1006, [$this->thmpTwin, $this->jimmie]);
    }

    private function vendor(string $name): Vendor
    {
        $this->vendorSequence++;

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.$this->vendorSequence,
            'name' => $name,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @param  list<Vendor>  $vendors
     * @param  array<string, mixed>  $attributes
     */
    private function workOrder(int $number, array $vendors, array $attributes = []): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(array_merge([
            'work_order_no' => $number,
            'status' => 'Open',
            'service_status_id' => $this->status->id,
        ], $attributes));

        $workOrder->vendors()->attach(collect($vendors)->pluck('id')->all());

        return $workOrder;
    }

    private function inertiaVersion(): string
    {
        $manifest = public_path('build/manifest.json');

        return file_exists($manifest) ? hash_file('xxh128', $manifest) : '';
    }

    /**
     * Every work_order_no across all columns of a board's deferred
     * service_status prop, fetched the way the pages do: a partial reload.
     *
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function boardWorkOrderNumbers(string $routeName, string $component, array $query = []): array
    {
        $response = $this->actingAs($this->staff)->get(route($routeName, $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->pluck('work_order_no')
            ->map(fn ($number) => (int) $number)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function mainBoard(array $query = []): array
    {
        return $this->boardWorkOrderNumbers('work_orders.index', 'WorkOrder/Index', $query);
    }

    /**
     * @return array<int, list<int>>
     */
    private function exclusionsProp(): array
    {
        $response = $this->actingAs($this->staff)->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
        ]);

        $response->assertOk();

        $map = $response->json('props.vendor_filter_exclusions');
        ksort($map);

        return $map;
    }

    public function test_the_thmp_filter_hides_work_orders_that_also_carry_jimmie_sfa(): void
    {
        $this->assertSame([1001, 1004], $this->mainBoard(['vendor' => $this->thmp->id]));
    }

    public function test_every_vendor_row_named_thmp_hides_them(): void
    {
        $this->assertSame([1005], $this->mainBoard(['vendor' => $this->thmpTwin->id]));
    }

    public function test_filtering_by_jimmie_sfa_still_shows_the_paired_work_orders(): void
    {
        $this->assertSame([1002, 1003, 1006], $this->mainBoard(['vendor' => $this->jimmie->id]));
    }

    public function test_other_vendors_are_unaffected(): void
    {
        $this->assertSame([1003], $this->mainBoard(['vendor' => $this->ace->id]));
        $this->assertSame([1004], $this->mainBoard(['vendor' => $this->sfaMichael->id]));
    }

    public function test_the_unfiltered_board_shows_everything(): void
    {
        $this->assertSame([1001, 1002, 1003, 1004, 1005, 1006], $this->mainBoard());
    }

    public function test_a_blank_setting_turns_the_rule_off(): void
    {
        config(['services.jobber.thmp_filter_hidden_vendors' => '']);

        $this->assertSame([1001, 1002, 1003, 1004], $this->mainBoard(['vendor' => $this->thmp->id]));
    }

    public function test_the_setting_takes_several_vendors(): void
    {
        config(['services.jobber.thmp_filter_hidden_vendors' => 'Jimmie Gendke SFA, sfa - michael']);

        $this->assertSame([1001], $this->mainBoard(['vendor' => $this->thmp->id]));
    }

    public function test_the_summary_popup_agrees_with_the_board(): void
    {
        $response = $this->actingAs($this->staff)
            ->getJson(route('work_orders.summary', ['board' => 'main', 'vendor' => $this->thmp->id]));

        $response->assertOk();
        $this->assertSame(2, $response->json('stats.work_orders.total'));
    }

    public function test_the_page_tells_the_cards_which_vendors_to_hide(): void
    {
        $this->assertSame([
            $this->thmp->id => [$this->jimmie->id],
            $this->thmpTwin->id => [$this->jimmie->id],
        ], $this->exclusionsProp());
    }

    public function test_the_page_sends_an_empty_map_when_the_rule_is_off(): void
    {
        config(['services.jobber.thmp_filter_hidden_vendors' => '']);

        $this->assertSame([], $this->exclusionsProp());
    }

    public function test_the_closed_board_follows_the_same_rule(): void
    {
        $this->workOrder(2001, [$this->thmp], ['status' => 'Closed']);
        $this->workOrder(2002, [$this->thmp, $this->jimmie], ['status' => 'Closed']);

        $closed = fn (Vendor $vendor) => $this->boardWorkOrderNumbers('work_orders.closed_work_orders', 'WorkOrder/Close', ['vendor' => $vendor->id]);

        $this->assertSame([2001], $closed($this->thmp));
        $this->assertSame([2002], $closed($this->jimmie));
    }

    public function test_the_paid_board_follows_the_same_rule(): void
    {
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);

        $paid = ['total_cost' => 150, 'completed_date' => now()->subDay()];
        $this->workOrder(3001, [$this->thmp], $paid);
        $this->workOrder(3002, [$this->thmp, $this->jimmie], $paid);

        $this->assertSame([3001], $this->boardWorkOrderNumbers('work_orders.paid', 'WorkOrder/Paid', ['vendor' => $this->thmp->id]));
    }
}
