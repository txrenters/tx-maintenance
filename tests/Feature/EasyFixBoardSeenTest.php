<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ActivityBoards;
use App\Services\HvacBoardActivityFeed;
use App\Services\HvacBoardNewCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "new activity" counters on the Tenant Easy Fix board: the HVAC board's
 * rules (every staff login, explicit Mark all seen, dismiss until it moves
 * again) on the board's own seen mark and dismissals.
 */
class EasyFixBoardSeenTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...User::STAFF_ROLES, 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->status = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole('woc');
    }

    private function vendor(): User
    {
        return User::factory()->create()->assignRole('vendor');
    }

    private function easyFixWorkOrder(int $number, array $attributes = []): WorkOrder
    {
        return WorkOrder::query()->create([
            'service_status_id' => $this->status->id,
            'work_order_no' => $number,
            'category' => 'Electrical',
            'type' => 'Service Request',
            'status' => 'Open',
            'easy_fix_key' => 'gfci_outlet',
            ...$attributes,
        ]);
    }

    private function inertiaGet(User $user, string $routeName): array
    {
        $response = $this->actingAs($user)->get(route($routeName), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        return $response->json('props');
    }

    public function test_every_staff_role_gets_the_counters_and_outsiders_do_not(): void
    {
        foreach (User::STAFF_ROLES as $role) {
            $this->assertTrue(User::factory()->create()->assignRole($role)->seesEasyFixBoardActivity(), $role);
        }

        $this->assertFalse($this->vendor()->seesEasyFixBoardActivity());
        $this->assertTrue($this->inertiaGet($this->staff(), 'work_orders.easy_fix')['shows_new_activity']);
    }

    /**
     * A login created after the deploy has no seen mark of its own and counts
     * from when the account was made, so it still gets badges (the browser
     * smoke found new logins stuck at zero with no way to switch them on).
     */
    public function test_a_login_created_after_the_deploy_still_gets_badges(): void
    {
        $this->travelTo(now()->subDay());
        $user = $this->staff();
        $user->forceFill(['easy_fix_board_seen_at' => null])->save();
        $this->travelBack();

        $this->easyFixWorkOrder(6009);

        $this->assertSame(1, $this->inertiaGet($user->fresh(), 'dashboard')['easy_fix_board_new_count']);
    }

    public function test_the_switch_turns_the_counters_off(): void
    {
        config(['services.easy_fix_board.badges_enabled' => false]);

        $this->assertFalse($this->inertiaGet($this->staff(), 'work_orders.easy_fix')['shows_new_activity']);
    }

    public function test_the_sidebar_count_reaches_every_page_for_staff_and_is_zero_for_vendors(): void
    {
        $user = $this->staff(['easy_fix_board_seen_at' => now()->subDay()]);
        $this->easyFixWorkOrder(6001);

        $this->assertSame(1, $this->inertiaGet($user, 'dashboard')['easy_fix_board_new_count']);
        $this->assertSame(0, $this->inertiaGet($this->vendor(), 'dashboard')['easy_fix_board_new_count']);
    }

    public function test_marking_seen_clears_the_count_and_only_for_the_caller(): void
    {
        $user = $this->staff(['easy_fix_board_seen_at' => now()->subDay()]);
        $colleague = $this->staff(['easy_fix_board_seen_at' => now()->subDay()]);
        $this->easyFixWorkOrder(6002);

        $counter = app(HvacBoardNewCounter::class);
        $this->assertSame(1, $counter->cachedCountFor($user->fresh(), ActivityBoards::EASY_FIX));

        $this->actingAs($user)->post(route('work_orders.easy_fix.seen'))->assertRedirect();

        $this->assertSame(0, $counter->cachedCountFor($user->fresh(), ActivityBoards::EASY_FIX));
        $this->assertSame(1, $counter->cachedCountFor($colleague->fresh(), ActivityBoards::EASY_FIX));
    }

    public function test_dismissing_one_row_clears_it_until_it_moves_again(): void
    {
        $user = $this->staff(['easy_fix_board_seen_at' => now()->subDay()]);
        $workOrder = $this->easyFixWorkOrder(6003);

        $this->actingAs($user)->getJson(route('work_orders.easy_fix.activity'))
            ->assertOk()
            ->assertJsonPath('new_count', 1)
            ->assertJsonPath('updates.0.work_order_no', 6003);

        $this->actingAs($user)->postJson(route('work_orders.easy_fix.dismiss', $workOrder))
            ->assertOk()
            ->assertJsonPath('new_count', 0);

        $this->travel(5)->seconds();
        $workOrder->forceFill(['priority' => 'HIGH'])->save();
        app(HvacBoardNewCounter::class)->forgetFor($user->id, ActivityBoards::EASY_FIX);

        $this->assertSame(1, app(HvacBoardNewCounter::class)->cachedCountFor($user->fresh(), ActivityBoards::EASY_FIX));
    }

    public function test_a_vendor_cannot_mark_seen_dismiss_or_read_the_feed(): void
    {
        $vendor = $this->vendor();
        $workOrder = $this->easyFixWorkOrder(6004);

        $this->actingAs($vendor)->post(route('work_orders.easy_fix.seen'))->assertForbidden();
        $this->actingAs($vendor)->postJson(route('work_orders.easy_fix.dismiss', $workOrder))->assertForbidden();
        $this->actingAs($vendor)->getJson(route('work_orders.easy_fix.activity'))->assertForbidden();

        $this->assertDatabaseCount('easy_fix_board_reads', 0);
    }

    /** The two boards keep separate seen marks, dismissals and counts. */
    public function test_the_hvac_and_easy_fix_boards_do_not_leak_into_each_other(): void
    {
        $user = $this->staff([
            'hvac_board_seen_at' => now()->subDay(),
            'easy_fix_board_seen_at' => now()->subDay(),
        ]);
        $easyFix = $this->easyFixWorkOrder(6005);
        WorkOrder::query()->create([
            'service_status_id' => $this->status->id,
            'work_order_no' => 6006,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $counter = app(HvacBoardNewCounter::class);
        $this->assertSame(1, $counter->cachedCountFor($user->fresh(), ActivityBoards::HVAC));
        $this->assertSame(1, $counter->cachedCountFor($user->fresh(), ActivityBoards::EASY_FIX));

        $this->actingAs($user)->postJson(route('work_orders.easy_fix.dismiss', $easyFix))->assertOk();
        $this->actingAs($user)->post(route('work_orders.hvac.seen'))->assertRedirect();

        $this->assertDatabaseCount('hvac_board_reads', 0);
        $this->assertDatabaseCount('easy_fix_board_reads', 1);
        $this->assertSame(0, $counter->cachedCountFor($user->fresh(), ActivityBoards::HVAC));
        $this->assertSame(0, $counter->cachedCountFor($user->fresh(), ActivityBoards::EASY_FIX));
        $this->assertNotNull($user->fresh()->easy_fix_board_seen_at);
        $this->assertTrue($user->fresh()->easy_fix_board_seen_at->lt(now()->subHours(23)));
    }

    public function test_the_modal_carries_easy_fix_tab_counts_only_for_easy_fix_work(): void
    {
        $user = $this->staff(['easy_fix_board_seen_at' => now()->subDay()]);
        $easyFix = $this->easyFixWorkOrder(6007);
        $other = $this->easyFixWorkOrder(6008, ['easy_fix_key' => null, 'category' => 'Plumbing']);

        foreach ([$easyFix, $other] as $workOrder) {
            DB::table('work_order_conversations')->insert([
                'work_order_id' => $workOrder->id,
                'message' => 'Tried the reset.',
                'conversation_type' => 'tenant',
                'is_read' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            ['conversation' => 1],
            app(HvacBoardActivityFeed::class)->tabCountsFor($user->fresh(), $easyFix, ActivityBoards::EASY_FIX),
        );

        $this->actingAs($user)->getJson(route('work_orders.data', $easyFix))
            ->assertOk()
            ->assertJsonPath('easy_fix_tab_counts.conversation', 1);

        $this->actingAs($user)->getJson(route('work_orders.data', $other))
            ->assertOk()
            ->assertJsonPath('easy_fix_tab_counts', []);
    }
}
