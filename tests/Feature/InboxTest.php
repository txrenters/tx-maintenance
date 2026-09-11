<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Inbox: every conversation in one list, grouped one row per work order and
 * party, so a coordinator can see who is still waiting without opening each
 * work order. Each row is the thread's newest incoming message — our own
 * outgoing texts, automated or typed, never take over the preview, the time or
 * the order of the list.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NUMBER = '+15125551111';

    private const THEIR_NUMBER = '+15125550000';

    private function staffUser(string $role = 'woc'): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function vendor(string $name, ?User $user = null): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'vendor_type' => 'Maintenance',
            'is_active' => true,
            'user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }

    /**
     * A vendor login, which Conversation's global scope limits to that vendor's
     * own work orders.
     *
     * @return array{0: User, 1: Vendor}
     */
    private function vendorLogin(): array
    {
        Role::findOrCreate('vendor', 'web');

        $user = User::factory()->create();
        $user->assignRole('vendor');

        return [$user, $this->vendor('Ace Plumbing', $user)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(WorkOrder $workOrder, bool $inbound, array $attributes = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $inbound ? 'Any update?' : 'We are on it.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? self::THEIR_NUMBER : self::OUR_NUMBER,
            'receiver_number' => $inbound ? self::OUR_NUMBER : self::THEIR_NUMBER,
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    public function test_an_inbound_tapback_previews_as_a_compact_reaction_and_stops_awaiting(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: [
            'message' => 'Liked “'.trim(str_repeat('Hello, TexasRenters.com has received a new service request. ', 5)).'”',
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = $response->viewData('page')['props']['threads'];

        $this->assertCount(1, $threads);
        $this->assertSame('👍 Liked a message', $threads[0]['preview']);
        // The created hook judged it a courtesy closer on arrival.
        $this->assertFalse($threads[0]['awaiting']);
    }

    public function test_a_disliked_tapback_previews_compactly_but_stays_awaiting(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: [
            'message' => 'Disliked “The visit is rescheduled to Friday”',
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = $response->viewData('page')['props']['threads'];

        $this->assertSame('👎 Disliked a message', $threads[0]['preview']);
        $this->assertTrue($threads[0]['awaiting']);
    }

    public function test_a_thread_holding_only_our_own_texts_is_not_listed(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: false, attributes: [
            'message' => 'Liked “Sounds good”',
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $props = $response->viewData('page')['props'];

        $this->assertCount(0, $props['threads']);
        $this->assertSame(0, $props['partyCounts']['all']);
    }

    public function test_a_thread_whose_newest_message_is_inbound_is_flagged_as_awaiting(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: false);
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $response->assertOk();

        $threads = $response->viewData('page')['props']['threads'];

        $this->assertCount(1, $threads, 'One work order and one party is one thread.');
        $this->assertTrue($threads[0]['awaiting']);
        $this->assertSame('Tenant', $threads[0]['party']);
    }

    public function test_a_thread_we_answered_last_is_not_flagged(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: false);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = $response->viewData('page')['props']['threads'];

        $this->assertCount(1, $threads);
        $this->assertFalse($threads[0]['awaiting']);
        $this->assertSame(0, $response->viewData('page')['props']['stats']['awaiting']);
        // The row still reads what the tenant said, not our reply.
        $this->assertSame('Any update?', $threads[0]['preview']);
    }

    public function test_the_row_keeps_the_incoming_message_after_our_later_text(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $question = $this->message($workOrder, inbound: true, attributes: [
            'message' => 'Is the plumber still coming today?',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        $this->message($workOrder, inbound: false, attributes: [
            'message' => 'Reminder: your appointment is tomorrow between 9 and 11.',
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = $response->viewData('page')['props']['threads'];

        $this->assertCount(1, $threads);
        $this->assertSame($question->id, $threads[0]['id']);
        $this->assertSame('Is the plumber still coming today?', $threads[0]['preview']);
        $this->assertSame(
            $question->created_at->format('Y-m-d H:i:s'),
            (string) $threads[0]['last_message_at'],
            'The row is stamped with when the question arrived, not when our text went out.'
        );
        // Something did go out since, so nobody is flagged as waiting.
        $this->assertFalse($threads[0]['awaiting']);
    }

    public function test_threads_are_ordered_by_their_incoming_message_not_by_our_texts(): void
    {
        $now = now();

        $olderQuestion = WorkOrder::factory()->create();
        $this->message($olderQuestion, inbound: true, attributes: [
            'created_at' => $now->copy()->subMinutes(10),
            'updated_at' => $now->copy()->subMinutes(10),
        ]);
        // Our text a minute ago must not lift this thread over the newer question.
        $this->message($olderQuestion, inbound: false, attributes: [
            'created_at' => $now->copy()->subMinute(),
            'updated_at' => $now->copy()->subMinute(),
        ]);

        $newerQuestion = WorkOrder::factory()->create();
        $this->message($newerQuestion, inbound: true, attributes: [
            'created_at' => $now->copy()->subMinutes(5),
            'updated_at' => $now->copy()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertSame(
            [$newerQuestion->id, $olderQuestion->id],
            $threads->pluck('work_order_id')->all(),
        );
    }

    public function test_each_party_on_a_work_order_gets_its_own_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(2, $threads);
        $this->assertEqualsCanonicalizing(
            ['Tenant', 'Owner'],
            $threads->pluck('party')->all(),
        );
    }

    public function test_two_vendors_on_one_work_order_are_separate_threads(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $first = $this->vendor('Ace Plumbing');
        $second = $this->vendor('Bolt Electric');

        $this->message($workOrder, inbound: true, attributes: [
            'conversation_type' => 'vendor',
            'vendor_id' => $first->id,
        ]);
        $this->message($workOrder, inbound: true, attributes: [
            'conversation_type' => 'vendor',
            'vendor_id' => $second->id,
        ]);

        $response = $this->actingAs($this->staffUser())->get(route('inbox.index'));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(2, $threads);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $threads->pluck('vendor_id')->all(),
        );
    }

    public function test_the_awaiting_filter_hides_answered_threads(): void
    {
        $answered = WorkOrder::factory()->create();
        $this->message($answered, inbound: false);

        $waiting = WorkOrder::factory()->create();
        $this->message($waiting, inbound: true);

        $response = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['status' => 'awaiting']));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(1, $threads);
        $this->assertSame($waiting->id, $threads->first()['work_order_id']);
    }

    public function test_the_party_filter_keeps_only_that_kind_of_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $response = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['party' => 'owner']));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(1, $threads);
        $this->assertSame('Owner', $threads->first()['party']);
    }

    public function test_every_party_chip_carries_its_own_count(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $second = WorkOrder::factory()->create();
        $this->message($second, inbound: true, attributes: ['conversation_type' => 'owner']);

        $counts = $this->actingAs($this->staffUser())
            ->get(route('inbox.index'))
            ->viewData('page')['props']['partyCounts'];

        $this->assertSame(3, $counts['all']);
        $this->assertSame(1, $counts['tenant']);
        $this->assertSame(2, $counts['owner']);
        // A party with nothing behind it still reports, so the chip can hide.
        $this->assertSame(0, $counts['vendor_owner']);
    }

    public function test_the_counts_are_not_narrowed_by_the_party_filter(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $counts = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['party' => 'owner']))
            ->viewData('page')['props']['partyCounts'];

        // Filtering to owners must not make the tenant chip read zero, or there
        // would be no way back to it.
        $this->assertSame(1, $counts['tenant']);
        $this->assertSame(1, $counts['owner']);
    }

    public function test_the_counts_follow_the_status_filter(): void
    {
        $waiting = WorkOrder::factory()->create();
        $this->message($waiting, inbound: true, attributes: ['conversation_type' => 'tenant']);

        $answered = WorkOrder::factory()->create();
        $this->message($answered, inbound: false, attributes: ['conversation_type' => 'owner']);

        $counts = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['status' => 'awaiting']))
            ->viewData('page')['props']['partyCounts'];

        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['tenant']);
        $this->assertSame(0, $counts['owner']);
    }

    public function test_an_unknown_party_falls_back_to_everyone(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);

        $response = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['party' => 'nonsense']));

        $props = $response->viewData('page')['props'];

        $this->assertSame('all', $props['filters']['party']);
        $this->assertCount(1, $props['threads']);
    }

    public function test_search_matches_the_work_order_number(): void
    {
        $wanted = WorkOrder::factory()->create(['work_order_no' => '778899']);
        $this->message($wanted, inbound: true);

        $other = WorkOrder::factory()->create(['work_order_no' => '112233']);
        $this->message($other, inbound: true);

        $response = $this->actingAs($this->staffUser())
            ->get(route('inbox.index', ['search' => '778899']));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(1, $threads);
        $this->assertSame($wanted->id, $threads->first()['work_order_id']);
    }

    public function test_opening_a_thread_returns_its_messages_oldest_first(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $first = $this->message($workOrder, inbound: true, attributes: ['message' => 'First']);
        $second = $this->message($workOrder, inbound: false, attributes: ['message' => 'Second']);

        $response = $this->actingAs($this->staffUser())->getJson(route('inbox.thread', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]));

        $response->assertOk();
        $response->assertJsonPath('messages.0.id', $first->id);
        $response->assertJsonPath('messages.1.id', $second->id);
        $response->assertJsonPath('work_order.id', $workOrder->id);
    }

    public function test_a_thread_reply_goes_back_to_whoever_wrote_in(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($this->staffUser())->getJson(route('inbox.thread', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]));

        $response->assertJsonPath('recipient_number', self::THEIR_NUMBER);
    }

    public function test_a_vendor_only_sees_threads_on_their_own_work_orders(): void
    {
        [$vendorUser, $vendor] = $this->vendorLogin();

        $theirs = WorkOrder::factory()->create();
        $theirs->vendors()->attach($vendor->id);
        $this->message($theirs, inbound: true, attributes: [
            'conversation_type' => 'vendor',
            'vendor_id' => $vendor->id,
        ]);

        $someoneElses = WorkOrder::factory()->create();
        $this->message($someoneElses, inbound: true, attributes: ['conversation_type' => 'vendor']);

        $response = $this->actingAs($vendorUser)->get(route('inbox.index'));

        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertCount(1, $threads, 'A vendor must never see another work order.');
        $this->assertSame($theirs->id, $threads->first()['work_order_id']);
    }

    public function test_a_thread_on_an_unreadable_work_order_is_not_served(): void
    {
        [$vendorUser] = $this->vendorLogin();

        $someoneElses = WorkOrder::factory()->create();
        $this->message($someoneElses, inbound: true, attributes: ['conversation_type' => 'vendor']);

        $this->actingAs($vendorUser)
            ->getJson(route('inbox.thread', [
                'work_order_id' => $someoneElses->id,
                'conversation_type' => 'vendor',
            ]))
            ->assertNotFound();
    }

    public function test_guests_cannot_reach_the_inbox(): void
    {
        $this->get(route('inbox.index'))->assertRedirect(route('login'));
    }

    public function test_older_threads_page_in_behind_a_cursor(): void
    {
        $now = now();

        for ($i = 0; $i < 55; $i++) {
            $workOrder = WorkOrder::factory()->create();
            $this->message($workOrder, inbound: true, attributes: [
                'created_at' => $now->copy()->subMinutes($i + 1),
                'updated_at' => $now->copy()->subMinutes($i + 1),
            ]);
        }

        $user = $this->staffUser();
        $props = $this->actingAs($user)
            ->get(route('inbox.index'))
            ->viewData('page')['props'];

        $this->assertCount(50, $props['threads']);
        $this->assertTrue($props['hasMore']);
        $this->assertNotNull($props['nextCursor']);

        $more = $this->actingAs($user)
            ->getJson(route('inbox.threads.more', array_merge(
                ['search' => '', 'party' => 'all', 'status' => 'all'],
                $props['nextCursor'],
            )))
            ->assertOk()
            ->json();

        $this->assertCount(5, $more['threads']);
        $this->assertFalse($more['has_more']);

        // The two pages never overlap, and together they cover everything.
        $firstPageKeys = array_column($props['threads'], 'key');
        $olderKeys = array_column($more['threads'], 'key');
        $this->assertSame([], array_intersect($firstPageKeys, $olderKeys));
        $this->assertCount(55, array_unique(array_merge($firstPageKeys, $olderKeys)));
    }

    public function test_the_cursor_page_respects_the_active_filters(): void
    {
        $now = now();

        $tenantThread = WorkOrder::factory()->create();
        $this->message($tenantThread, inbound: true, attributes: [
            'created_at' => $now->copy()->subMinutes(2),
            'updated_at' => $now->copy()->subMinutes(2),
        ]);

        $ownerThread = WorkOrder::factory()->create();
        $this->message($ownerThread, inbound: true, attributes: [
            'conversation_type' => 'owner',
            'created_at' => $now->copy()->subMinutes(3),
            'updated_at' => $now->copy()->subMinutes(3),
        ]);

        $more = $this->actingAs($this->staffUser())
            ->getJson(route('inbox.threads.more', [
                'search' => '',
                'party' => 'owner',
                'status' => 'all',
                'before_created_at' => $now->format('Y-m-d H:i:s'),
                'before_id' => PHP_INT_MAX,
            ]))
            ->assertOk()
            ->json();

        $this->assertCount(1, $more['threads']);
        $this->assertSame('owner', $more['threads'][0]['conversation_type']);
    }
}
