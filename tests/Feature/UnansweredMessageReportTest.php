<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Inbox summary report: who has written in and not been answered.
 *
 * Every test here runs with the AI provider unconfigured, so the deterministic
 * write-up is what is asserted. That is the half that must always be right —
 * the AI only rephrases figures it is handed.
 */
class UnansweredMessageReportTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NUMBER = '+15125551111';

    private const THEIR_NUMBER = '+15125550000';

    protected function setUp(): void
    {
        parent::setUp();

        // No key means no network call and a predictable fallback, whatever the
        // developer happens to have in their own .env.
        config([
            'ai.default' => 'openai',
            'ai.providers.openai.key' => '',
        ]);
    }

    private function staffUser(string $role = 'woc'): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(WorkOrder $workOrder, bool $inbound, array $attributes = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $inbound ? 'The water heater is still out.' : 'A plumber is booked.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? self::THEIR_NUMBER : self::OUR_NUMBER,
            'receiver_number' => $inbound ? self::OUR_NUMBER : self::THEIR_NUMBER,
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function report(?User $user = null): array
    {
        return $this->actingAs($user ?? $this->staffUser())
            ->getJson(route('inbox.summary'))
            ->assertOk()
            ->json();
    }

    public function test_only_threads_nobody_answered_are_counted(): void
    {
        $waiting = WorkOrder::factory()->create();
        $this->message($waiting, inbound: true);

        $answered = WorkOrder::factory()->create();
        $this->message($answered, inbound: true);
        $this->message($answered, inbound: false);

        $report = $this->report();

        $this->assertSame(1, $report['stats']['threads']);
        $this->assertSame(1, $report['stats']['work_orders']);
    }

    public function test_each_party_is_counted_separately(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $second = WorkOrder::factory()->create();
        $this->message($second, inbound: true, attributes: ['conversation_type' => 'owner']);

        $byParty = collect($this->report()['stats']['by_party'])
            ->pluck('total', 'party');

        $this->assertSame(1, $byParty['Tenant']);
        $this->assertSame(2, $byParty['Owner']);
    }

    public function test_waits_are_bucketed_by_how_long_they_have_been_sitting(): void
    {
        $fresh = WorkOrder::factory()->create();
        $this->message($fresh, inbound: true)->update(['created_at' => now()->subHour()]);

        $today = WorkOrder::factory()->create();
        $this->message($today, inbound: true)->update(['created_at' => now()->subHours(8)]);

        $stale = WorkOrder::factory()->create();
        $this->message($stale, inbound: true)->update(['created_at' => now()->subDays(2)]);

        $ancient = WorkOrder::factory()->create();
        $this->message($ancient, inbound: true)->update(['created_at' => now()->subDays(9)]);

        $age = $this->report()['stats']['by_age'];

        $this->assertSame(1, $age['under_4h']);
        $this->assertSame(1, $age['four_to_24h']);
        $this->assertSame(1, $age['one_to_three_days']);
        $this->assertSame(1, $age['over_three_days']);
    }

    public function test_the_longest_waiting_thread_is_named_and_quoted_first(): void
    {
        $tenant = Tenants::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Mitchell',
            'mobile_phone' => self::THEIR_NUMBER,
        ]);

        $oldest = WorkOrder::factory()->create(['tenant_id' => $tenant->id]);
        $this->message($oldest, inbound: true, attributes: ['message' => 'Still no hot water.'])
            ->update(['created_at' => now()->subDays(3)]);

        $newer = WorkOrder::factory()->create();
        $this->message($newer, inbound: true)->update(['created_at' => now()->subHour()]);

        $details = $this->report()['stats']['details'];

        $this->assertSame(1, $details[0]['ref']);
        $this->assertSame('Sarah Mitchell', $details[0]['counterparty']);
        $this->assertSame('Still no hot water.', $details[0]['message']);
        $this->assertSame(72, $details[0]['waiting_hours']);
    }

    public function test_a_detail_carries_the_key_the_inbox_list_uses(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $detail = $this->report()['stats']['details'][0];

        $listKey = collect(
            $this->actingAs($this->staffUser())
                ->get(route('inbox.index'))
                ->viewData('page')['props']['threads']
        )->first()['key'];

        // The report opens threads by this key, so a mismatch would silently
        // open nothing.
        $this->assertSame($listKey, $detail['key']);
    }

    public function test_the_written_report_ranks_the_longest_waits_highest(): void
    {
        $urgent = WorkOrder::factory()->create();
        $this->message($urgent, inbound: true)->update(['created_at' => now()->subDays(2)]);

        $recent = WorkOrder::factory()->create();
        $this->message($recent, inbound: true)->update(['created_at' => now()->subMinutes(30)]);

        $report = $this->report();

        $this->assertSame('fallback', $report['source']);
        $this->assertStringContainsString('2 conversations', $report['summary']['headline']);

        $priorities = collect($report['summary']['priorities']);

        $this->assertSame('high', $priorities->first()['urgency']);
        $this->assertSame('low', $priorities->last()['urgency']);
    }

    public function test_an_emergency_work_order_is_urgent_however_briefly_it_has_waited(): void
    {
        $workOrder = WorkOrder::factory()->create(['is_emergency' => true]);
        $this->message($workOrder, inbound: true)->update(['created_at' => now()->subMinutes(5)]);

        $priority = $this->report()['summary']['priorities'][0];

        $this->assertTrue($priority['is_emergency']);
        $this->assertSame('high', $priority['urgency']);
    }

    public function test_an_empty_queue_reports_that_plainly(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: false);

        $report = $this->report();

        $this->assertSame(0, $report['stats']['threads']);
        $this->assertSame('empty', $report['source']);
        $this->assertSame([], $report['summary']['priorities']);
        $this->assertStringContainsString('Nothing is waiting', $report['summary']['headline']);
    }

    public function test_a_vendor_cannot_pull_the_report(): void
    {
        Role::findOrCreate('vendor', 'web');
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');

        $this->actingAs($vendor)
            ->getJson(route('inbox.summary'))
            ->assertForbidden();
    }

    public function test_guests_cannot_pull_the_report(): void
    {
        $this->get(route('inbox.summary'))->assertRedirect(route('login'));
    }
}
