<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HvacBoardNewCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "new activity" counters on the HVAC board: who sees them, what clears
 * them, and the query shape they are allowed to run.
 */
class HvacBoardSeenTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_EMAIL = 'coordinator@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hvac_board.badge_emails' => self::ALLOWED_EMAIL.',it@example.com']);
    }

    private function allowedUser(): User
    {
        return User::factory()->create(['email' => self::ALLOWED_EMAIL]);
    }

    /** The board page props, without the deferred board itself. */
    private function boardProps(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.hvac'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        return $response->json('props');
    }

    public function test_an_allow_listed_user_gets_the_counters(): void
    {
        $this->assertTrue($this->boardProps($this->allowedUser())['shows_new_activity']);
    }

    /**
     * Several users hold the woc role; only the coordinator who works this board
     * should see the counters, so the gate is the allow-list and not the role.
     */
    public function test_another_staff_user_does_not_get_the_counters(): void
    {
        $other = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->assertFalse($this->boardProps($other)['shows_new_activity']);
    }

    public function test_the_allow_list_ignores_case_and_surrounding_space(): void
    {
        config(['services.hvac_board.badge_emails' => '  COORDINATOR@Example.com , it@example.com ']);

        $this->assertTrue($this->boardProps($this->allowedUser())['shows_new_activity']);
    }

    /** The board marks what moved by comparing this against each card. */
    public function test_the_board_exposes_the_users_own_seen_timestamp(): void
    {
        $user = $this->allowedUser();
        $user->forceFill(['hvac_board_seen_at' => '2026-09-01 12:00:00'])->save();

        $this->assertNotNull($this->boardProps($user)['board_seen_at']);
    }

    /**
     * A user who has never marked the board seen gets a null mark, and the
     * board treats that as "nothing is new" rather than "everything is" — the
     * same reason the migration backfills existing users to now().
     */
    public function test_a_user_who_never_marked_it_seen_gets_a_null_mark(): void
    {
        $user = $this->allowedUser();
        $user->forceFill(['hvac_board_seen_at' => null])->save();

        $this->assertNull($this->boardProps($user)['board_seen_at']);
    }

    /** Without updated_at on the cards the browser has nothing to compare. */
    public function test_board_cards_carry_their_updated_at(): void
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3001,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $response = $this->actingAs($this->allowedUser())->get(route('work_orders.hvac'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Hvac',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $card = collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->firstWhere('work_order_no', 3001);

        $this->assertNotNull($card);
        $this->assertArrayHasKey('updated_at', $card);
        $this->assertNotNull($card['updated_at']);
    }

    public function test_marking_the_board_seen_stamps_only_the_caller(): void
    {
        $user = $this->allowedUser();
        $other = User::factory()->create([
            'email' => 'it@example.com',
            'hvac_board_seen_at' => null,
        ]);

        $this->actingAs($user)->post(route('work_orders.hvac.seen'))->assertRedirect();

        $this->assertNotNull($user->fresh()->hvac_board_seen_at);
        $this->assertNull($other->fresh()->hvac_board_seen_at);
    }

    /**
     * Hiding the button is not authorization: the route itself has to refuse
     * anyone who has no counters to clear.
     */
    public function test_a_user_off_the_allow_list_cannot_mark_the_board_seen(): void
    {
        $other = User::factory()->create([
            'email' => 'someone.else@example.com',
            'hvac_board_seen_at' => null,
        ]);

        $this->actingAs($other)->post(route('work_orders.hvac.seen'))->assertForbidden();

        $this->assertNull($other->fresh()->hvac_board_seen_at);
    }

    public function test_a_guest_cannot_mark_the_board_seen(): void
    {
        $this->post(route('work_orders.hvac.seen'))->assertRedirect(route('login'));
    }

    /**
     * The sidebar number is what makes movement visible without opening the
     * board, so it has to be shared on an ordinary page, not just the board.
     */
    public function test_the_sidebar_count_reaches_every_page_for_an_allow_listed_user(): void
    {
        $user = $this->allowedUser();
        $user->forceFill(['hvac_board_seen_at' => now()->subDay()])->save();

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3010,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('props.hvac_board_new_count'));
    }

    public function test_the_sidebar_count_stays_zero_for_everyone_else(): void
    {
        $other = User::factory()->create([
            'email' => 'someone.else@example.com',
            'hvac_board_seen_at' => now()->subDay(),
        ]);

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3011,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $response = $this->actingAs($other)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('props.hvac_board_new_count'));
    }

    /** Marking the board seen must drop the number now, not in a minute. */
    public function test_marking_seen_clears_the_sidebar_count_immediately(): void
    {
        $user = $this->allowedUser();
        $user->forceFill(['hvac_board_seen_at' => now()->subDay()])->save();

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3012,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $counter = app(HvacBoardNewCounter::class);
        $this->assertSame(1, $counter->cachedCountFor($user->fresh()));

        $this->actingAs($user)->post(route('work_orders.hvac.seen'))->assertRedirect();

        $this->assertSame(0, $counter->cachedCountFor($user->fresh()));
    }

    /** The dropdown names what happened where a child record explains it. */
    public function test_the_activity_feed_names_the_change(): void
    {
        $user = $this->allowedUser();
        $user->forceFill(['hvac_board_seen_at' => now()->subDay()])->save();

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        $messaged = WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3020,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        DB::table('work_order_conversations')->insert([
            'work_order_id' => $messaged->id,
            'message' => 'The AC is still not cooling.',
            'conversation_type' => 'tenant',
            'is_read' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Moved, but nothing explains how — the honest fallback.
        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3021,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $updates = collect(
            $this->actingAs($user)->getJson(route('work_orders.hvac.activity'))
                ->assertOk()
                ->json('updates')
        )->keyBy('work_order_no');

        $this->assertSame('new message', $updates[3020]['change']);
        $this->assertSame('updated', $updates[3021]['change']);
    }

    /** Nothing has moved, so the dropdown has nothing to show. */
    public function test_the_activity_feed_is_empty_when_nothing_moved(): void
    {
        $user = $this->allowedUser();

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3022,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        // Marked seen after the work order was created.
        $user->forceFill(['hvac_board_seen_at' => now()->addMinute()])->save();

        $this->actingAs($user)->getJson(route('work_orders.hvac.activity'))
            ->assertOk()
            ->assertJsonCount(0, 'updates');
    }

    public function test_a_user_off_the_allow_list_cannot_read_the_activity_feed(): void
    {
        $other = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->actingAs($other)->getJson(route('work_orders.hvac.activity'))->assertForbidden();
    }

    /**
     * The 2026-09-11 outage: a badge count joined a JSON-extract derived table
     * over the activity log onto every message row, and every cache miss pinned
     * a PHP worker until the pool drained. These counters are computed in the
     * browser from the board payload, so the board must never grow a query of
     * that shape.
     */
    public function test_the_board_runs_no_activity_log_or_json_query(): void
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Scheduled',
            'description' => 'Scheduled',
        ]);

        WorkOrder::query()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 3002,
            'category' => 'HVAC ',
            'type' => 'Service Request',
            'status' => 'Open',
        ]);

        $offending = [];

        DB::listen(function ($query) use (&$offending) {
            $sql = strtolower($query->sql);

            foreach (['activity_log', 'json_extract', 'json_unquote'] as $needle) {
                if (str_contains($sql, $needle)) {
                    $offending[] = $query->sql;
                }
            }
        });

        $this->actingAs($this->allowedUser())->get(route('work_orders.hvac'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Hvac',
            'X-Inertia-Partial-Data' => 'service_status',
        ])->assertOk();

        $this->assertSame([], $offending, 'The HVAC board must not query the activity log or extract JSON.');
    }
}
