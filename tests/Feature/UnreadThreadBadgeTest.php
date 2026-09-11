<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\UnreadThreadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The red count on the Messages nav: how many conversations hold a message
 * this user has not looked at yet. Messenger semantics — opening a thread
 * clears it, replying is not required — so the badge drains as people read
 * instead of sitting at the whole reply backlog. A coordinator's reply clears
 * it for everyone; an automated text going out clears nothing.
 */
class UnreadThreadBadgeTest extends TestCase
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

    private function openThread(User $user, WorkOrder $workOrder): void
    {
        $this->actingAs($user)
            ->getJson(route('inbox.thread', [
                'work_order_id' => $workOrder->id,
                'conversation_type' => 'tenant',
            ]))
            ->assertOk();
    }

    public function test_an_unopened_inbound_thread_counts(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $this->assertSame(1, app(UnreadThreadCounter::class)->countFor($this->staffUser()->id));
    }

    public function test_reading_clears_it_for_the_reader_only(): void
    {
        $reader = $this->staffUser();
        $colleague = $this->staffUser();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $this->openThread($reader, $workOrder);

        $counter = app(UnreadThreadCounter::class);
        $this->assertSame(0, $counter->countFor($reader->id));
        $this->assertSame(1, $counter->countFor($colleague->id));
    }

    public function test_reading_busts_the_readers_cached_count_immediately(): void
    {
        $reader = $this->staffUser();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $counter = app(UnreadThreadCounter::class);
        $this->assertSame(1, $counter->cachedCountFor($reader->id));

        $this->openThread($reader, $workOrder);

        $this->assertSame(0, $counter->cachedCountFor($reader->id));
    }

    public function test_a_new_message_after_reading_counts_again(): void
    {
        $reader = $this->staffUser();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->openThread($reader, $workOrder);

        $this->message($workOrder, inbound: true, attributes: ['message' => 'Still waiting…']);

        $this->assertSame(1, app(UnreadThreadCounter::class)->countFor($reader->id));
    }

    public function test_a_thread_we_answered_never_counts(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        // A typed reply: a coordinator has dealt with it, for everyone.
        $this->message($workOrder, inbound: false);

        $this->assertSame(0, app(UnreadThreadCounter::class)->countFor($this->staffUser()->id));
    }

    public function test_an_automated_text_never_clears_the_badge(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $reminder = $this->message($workOrder, inbound: false, attributes: [
            'message' => 'Reminder: the vendor visits tomorrow.',
        ]);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_schedule_follow_up_sms',
            self::THEIR_NUMBER,
            $workOrder,
            $reminder->message,
            ['conversation_id' => $reminder->id],
        );

        $this->assertSame(1, app(UnreadThreadCounter::class)->countFor($this->staffUser()->id));
    }

    public function test_a_counting_failure_shows_no_badge_rather_than_breaking_the_page(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        // The badge is a shared prop on every page a coordinator opens; a
        // broken table underneath it must not take those pages down.
        Schema::drop('inbox_thread_reads');

        $this->assertSame(0, app(UnreadThreadCounter::class)->cachedCountFor($this->staffUser()->id));
    }

    public function test_closed_work_orders_never_count(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrder::CLOSED_STATUSES[0]]);
        $this->message($workOrder, inbound: true);

        $this->assertSame(0, app(UnreadThreadCounter::class)->countFor($this->staffUser()->id));
    }
}
