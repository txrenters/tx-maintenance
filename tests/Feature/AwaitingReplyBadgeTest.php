<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AwaitingReplyCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The red count on the Messages nav: how many conversations are still sitting
 * on a reply from us.
 */
class AwaitingReplyBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
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
            'message' => $inbound ? 'Any update?' : 'We are on it.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? '+15125550000' : '+15125551111',
            'receiver_number' => $inbound ? '+15125551111' : '+15125550000',
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    public function test_it_counts_only_threads_whose_newest_message_is_inbound(): void
    {
        $waiting = WorkOrder::factory()->create();
        $this->message($waiting, inbound: false);
        $this->message($waiting, inbound: true);

        $answered = WorkOrder::factory()->create();
        $this->message($answered, inbound: true);
        $this->message($answered, inbound: false);

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_each_party_on_a_work_order_counts_separately(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        $this->assertSame(2, app(AwaitingReplyCounter::class)->count());
    }

    public function test_replying_clears_the_cached_count(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $counter = app(AwaitingReplyCounter::class);
        $this->assertSame(1, $counter->cachedCount());

        // Creating a message busts the cache from the model, so the badge does
        // not sit stale for the rest of the TTL after a coordinator replies.
        $this->message($workOrder, inbound: false);

        $this->assertSame(0, $counter->cachedCount());
    }

    public function test_staff_receive_the_count_as_a_shared_prop(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($this->user('woc'))->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('page')['props']['awaiting_reply_count']);
    }

    public function test_non_staff_never_pay_for_the_query(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($this->user('vendor'))->get(route('dashboard'));

        $this->assertSame(0, $response->viewData('page')['props']['awaiting_reply_count']);
    }
}
