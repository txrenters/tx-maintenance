<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The boards' Vendor filter when the selected vendor is THMP.
 *
 * Staff tag THMP onto Jimmie Gendke SFA's work orders only so the app creates
 * the Jobber job; those work orders are SFA's and must stay off THMP's own
 * queue — on the boards and in the Summary popup alike. The rule is per login
 * (THMP's own, John Carlo's, by default): every other staff member still sees
 * SFA's work orders under the THMP filter so they can process them. Every
 * other vendor, and the unfiltered board, are unchanged for everyone.
 */
class WorkOrderVendorFilterTest extends TestCase
{
    use RefreshDatabase;

    /** THMP's own login, the one the rule applies to. */
    private User $johnCarlo;

    /** A coordinator: sees everything under the THMP filter. */
    private User $coordinator;

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

        $this->johnCarlo = $this->staff('xservice@txhomemp.com');
        $this->coordinator = $this->staff('woc@texasrenter.com');

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

    private function staff(string $email): User
    {
        Role::findOrCreate('woc', 'web');

        $user = User::factory()->create(['email' => $email]);
        $user->assignRole('woc');

        return $user;
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
    private function boardWorkOrderNumbers(User $user, string $routeName, string $component, array $query = []): array
    {
        $response = $this->actingAs($user)->get(route($routeName, $query), [
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
    private function mainBoard(User $user, array $query = []): array
    {
        return $this->boardWorkOrderNumbers($user, 'work_orders.index', 'WorkOrder/Index', $query);
    }

    /**
     * @return array<int, list<int>>
     */
    private function exclusionsProp(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
        ]);

        $response->assertOk();

        $map = $response->json('props.vendor_filter_exclusions');
        ksort($map);

        return $map;
    }

    private function summaryTotal(User $user, Vendor $vendor): int
    {
        $response = $this->actingAs($user)
            ->getJson(route('work_orders.summary', ['board' => 'main', 'vendor' => $vendor->id]));

        $response->assertOk();

        return $response->json('stats.work_orders.total');
    }

    public function test_the_thmp_filter_hides_work_orders_that_also_carry_jimmie_sfa_for_thmps_login(): void
    {
        $this->assertSame([1001, 1004], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmp->id]));
    }

    public function test_every_vendor_row_named_thmp_hides_them(): void
    {
        $this->assertSame([1005], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmpTwin->id]));
    }

    public function test_a_coordinator_still_sees_every_work_order_under_the_thmp_filter(): void
    {
        $this->assertSame([1001, 1002, 1003, 1004], $this->mainBoard($this->coordinator, ['vendor' => $this->thmp->id]));
        $this->assertSame([1005, 1006], $this->mainBoard($this->coordinator, ['vendor' => $this->thmpTwin->id]));
    }

    public function test_the_login_match_ignores_case_and_whitespace(): void
    {
        config(['services.jobber.thmp_filter_users' => ' XService@TXHomeMP.com , someone@else.com']);

        $this->assertSame([1001, 1004], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmp->id]));
    }

    public function test_a_blank_login_list_switches_the_rule_off_for_everyone(): void
    {
        config(['services.jobber.thmp_filter_users' => '']);

        $this->assertSame([1001, 1002, 1003, 1004], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmp->id]));
    }

    public function test_filtering_by_jimmie_sfa_still_shows_the_paired_work_orders(): void
    {
        $this->assertSame([1002, 1003, 1006], $this->mainBoard($this->johnCarlo, ['vendor' => $this->jimmie->id]));
        $this->assertSame([1002, 1003, 1006], $this->mainBoard($this->coordinator, ['vendor' => $this->jimmie->id]));
    }

    public function test_other_vendors_are_unaffected(): void
    {
        $this->assertSame([1003], $this->mainBoard($this->johnCarlo, ['vendor' => $this->ace->id]));
        $this->assertSame([1004], $this->mainBoard($this->johnCarlo, ['vendor' => $this->sfaMichael->id]));
    }

    public function test_the_unfiltered_board_shows_everything(): void
    {
        $this->assertSame([1001, 1002, 1003, 1004, 1005, 1006], $this->mainBoard($this->johnCarlo));
        $this->assertSame([1001, 1002, 1003, 1004, 1005, 1006], $this->mainBoard($this->coordinator));
    }

    public function test_a_blank_vendor_setting_turns_the_rule_off(): void
    {
        config(['services.jobber.thmp_filter_hidden_vendors' => '']);

        $this->assertSame([1001, 1002, 1003, 1004], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmp->id]));
    }

    public function test_the_vendor_setting_takes_several_vendors(): void
    {
        config(['services.jobber.thmp_filter_hidden_vendors' => 'Jimmie Gendke SFA, sfa - michael']);

        $this->assertSame([1001], $this->mainBoard($this->johnCarlo, ['vendor' => $this->thmp->id]));
    }

    public function test_the_summary_popup_agrees_with_each_login_board(): void
    {
        $this->assertSame(2, $this->summaryTotal($this->johnCarlo, $this->thmp));
        $this->assertSame(4, $this->summaryTotal($this->coordinator, $this->thmp));
    }

    public function test_the_page_tells_thmps_login_which_vendors_to_hide(): void
    {
        $this->assertSame([
            $this->thmp->id => [$this->jimmie->id],
            $this->thmpTwin->id => [$this->jimmie->id],
        ], $this->exclusionsProp($this->johnCarlo));
    }

    public function test_the_page_sends_an_empty_map_to_everyone_else(): void
    {
        $this->assertSame([], $this->exclusionsProp($this->coordinator));
    }

    /**
     * The guarantee everyone else relies on: for a login the rule does not
     * apply to, the scope that replaced the boards' inline vendor clause
     * builds exactly the SQL that clause built, with the same bindings, and
     * resolving the rule costs no query at all.
     */
    public function test_for_everyone_else_the_scope_is_the_old_clause_and_costs_nothing(): void
    {
        $this->actingAs($this->coordinator);

        $before = WorkOrder::query()->whereHas('vendors', fn ($q) => $q->where('work_order_vendors.vendor_id', $this->thmp->id));
        $after = WorkOrder::query()->assignedToVendor($this->thmp->id);

        $this->assertSame($before->toSql(), $after->toSql());
        $this->assertSame($before->getBindings(), $after->getBindings());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame([], Vendor::thmpFilterExclusions());
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_for_thmps_login_the_scope_only_adds_the_exclusion(): void
    {
        $this->actingAs($this->johnCarlo);

        $plain = WorkOrder::query()->whereHas('vendors', fn ($q) => $q->where('work_order_vendors.vendor_id', $this->thmp->id))->toSql();
        $trimmed = WorkOrder::query()->assignedToVendor($this->thmp->id)->toSql();

        $this->assertStringStartsWith($plain, $trimmed);
        $this->assertSame(1, substr_count($trimmed, 'not exists'));
        $this->assertSame(0, substr_count($plain, 'not exists'));

        // Nobody signed in (a queued or console caller): plain clause, no rule.
        auth()->logout();
        $this->assertSame($plain, WorkOrder::query()->assignedToVendor($this->thmp->id)->toSql());
    }

    public function test_the_closed_board_follows_the_same_rule(): void
    {
        $this->workOrder(2001, [$this->thmp], ['status' => 'Closed']);
        $this->workOrder(2002, [$this->thmp, $this->jimmie], ['status' => 'Closed']);

        $closed = fn (User $user, Vendor $vendor) => $this->boardWorkOrderNumbers($user, 'work_orders.closed_work_orders', 'WorkOrder/Close', ['vendor' => $vendor->id]);

        $this->assertSame([2001], $closed($this->johnCarlo, $this->thmp));
        $this->assertSame([2002], $closed($this->johnCarlo, $this->jimmie));
        $this->assertSame([2001, 2002], $closed($this->coordinator, $this->thmp));
    }

    public function test_the_paid_board_follows_the_same_rule(): void
    {
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);

        $paid = ['total_cost' => 150, 'completed_date' => now()->subDay()];
        $this->workOrder(3001, [$this->thmp], $paid);
        $this->workOrder(3002, [$this->thmp, $this->jimmie], $paid);

        $this->assertSame([3001], $this->boardWorkOrderNumbers($this->johnCarlo, 'work_orders.paid', 'WorkOrder/Paid', ['vendor' => $this->thmp->id]));
        $this->assertSame([3001, 3002], $this->boardWorkOrderNumbers($this->coordinator, 'work_orders.paid', 'WorkOrder/Paid', ['vendor' => $this->thmp->id]));
    }
}
