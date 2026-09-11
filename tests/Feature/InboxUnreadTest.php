<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\InboxThreadRead;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Per-staff-user unread state on the Inbox: a thread with a new inbound
 * message reads as unread until this user opens it or a coordinator replies,
 * and opening it records a marker without touching is_read (the direction
 * column). An automated text going out is not a reply and never clears it.
 */
class InboxUnreadTest extends TestCase
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

    private function message(WorkOrder $workOrder, bool $inbound): Conversation
    {
        return Conversation::query()->create([
            'message' => $inbound ? 'Any update?' : 'We are on it.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? self::THEIR_NUMBER : self::OUR_NUMBER,
            'receiver_number' => $inbound ? self::OUR_NUMBER : self::THEIR_NUMBER,
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ]);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function threadsFor(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('inbox.index', $query))
            ->viewData('page')['props']['threads'];
    }

    public function test_a_new_inbound_thread_is_unread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $threads = $this->threadsFor($this->staffUser());

        $this->assertCount(1, $threads);
        $this->assertTrue($threads[0]['unread']);
        $this->assertTrue($threads[0]['awaiting']);
    }

    /**
     * An outgoing text the automated-message ledger claims — the only record
     * of which texts no person typed.
     */
    private function automatedText(WorkOrder $workOrder): Conversation
    {
        $text = $this->message($workOrder, inbound: false);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_schedule_follow_up_sms',
            self::THEIR_NUMBER,
            $workOrder,
            $text->message,
            ['conversation_id' => $text->id],
        );

        return $text;
    }

    public function test_a_thread_we_answered_last_is_never_unread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        // A typed reply: a coordinator has dealt with it, for everyone.
        $this->message($workOrder, inbound: false);

        $threads = $this->threadsFor($this->staffUser());

        $this->assertFalse($threads[0]['unread']);
    }

    public function test_an_automated_text_never_clears_unread(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->automatedText($workOrder);

        $threads = $this->threadsFor($user);

        $this->assertCount(1, $threads);
        $this->assertTrue($threads[0]['unread'], 'A reminder going out is not anyone reading the message.');
        // ...but something did go out, so the thread is not flagged as waiting.
        $this->assertFalse($threads[0]['awaiting']);

        $unread = $this->threadsFor($user, ['status' => 'unread']);

        $this->assertCount(1, $unread);
        $this->assertSame($workOrder->id, $unread[0]['work_order_id']);
    }

    public function test_a_typed_reply_after_an_automated_text_clears_unread(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->automatedText($workOrder);
        $this->message($workOrder, inbound: false);

        $this->assertFalse($this->threadsFor($user)[0]['unread']);
        $this->assertCount(0, $this->threadsFor($user, ['status' => 'unread']));
    }

    public function test_opening_the_thread_clears_unread_after_an_automated_text_too(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->automatedText($workOrder);

        $this->openThread($user, $workOrder);

        $this->assertFalse($this->threadsFor($user)[0]['unread']);
    }

    public function test_a_newer_question_after_a_typed_reply_is_unread_again(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: false);
        $this->message($workOrder, inbound: true);

        $threads = $this->threadsFor($user);

        $this->assertTrue($threads[0]['unread']);
        $this->assertTrue($threads[0]['awaiting']);
    }

    public function test_opening_a_thread_marks_it_read_without_touching_is_read(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $inbound = $this->message($workOrder, inbound: true);

        $this->openThread($user, $workOrder);

        $this->assertDatabaseHas('inbox_thread_reads', [
            'user_id' => $user->id,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'vendor_id' => 0,
            'owner_id' => 0,
            'last_read_conversation_id' => $inbound->id,
        ]);

        // The direction column must never be flipped by reading.
        $this->assertFalse((bool) $inbound->fresh()->is_read);

        $threads = $this->threadsFor($user);

        $this->assertFalse($threads[0]['unread']);
        // Still awaiting a reply — reading is not answering.
        $this->assertTrue($threads[0]['awaiting']);
    }

    public function test_a_newer_inbound_message_makes_the_thread_unread_again(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $this->openThread($user, $workOrder);
        $this->message($workOrder, inbound: true);

        $threads = $this->threadsFor($user);

        $this->assertTrue($threads[0]['unread']);
    }

    public function test_read_state_is_per_staff_user(): void
    {
        $reader = $this->staffUser();
        $colleague = $this->staffUser('admin');
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $this->openThread($reader, $workOrder);

        $this->assertFalse($this->threadsFor($reader)[0]['unread']);
        $this->assertTrue($this->threadsFor($colleague)[0]['unread']);
    }

    public function test_the_unread_filter_lists_only_unread_threads(): void
    {
        $user = $this->staffUser();
        $readWorkOrder = WorkOrder::factory()->create();
        $unreadWorkOrder = WorkOrder::factory()->create();
        $this->message($readWorkOrder, inbound: true);
        $this->message($unreadWorkOrder, inbound: true);

        $this->openThread($user, $readWorkOrder);

        $threads = $this->threadsFor($user, ['status' => 'unread']);

        $this->assertCount(1, $threads);
        $this->assertSame($unreadWorkOrder->id, $threads[0]['work_order_id']);
    }

    public function test_reopening_never_moves_the_marker_backwards(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $first = $this->message($workOrder, inbound: true);

        $this->openThread($user, $workOrder);

        // A marker ahead of the thread (e.g. from a message deleted later) must
        // survive a reopen unchanged.
        InboxThreadRead::query()
            ->where('user_id', $user->id)
            ->update(['last_read_conversation_id' => $first->id + 100]);

        $this->openThread($user, $workOrder);

        $this->assertDatabaseHas('inbox_thread_reads', [
            'user_id' => $user->id,
            'last_read_conversation_id' => $first->id + 100,
        ]);
    }

    public function test_unread_count_is_in_the_stats(): void
    {
        $user = $this->staffUser();
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true);

        $stats = $this->actingAs($user)
            ->get(route('inbox.index'))
            ->viewData('page')['props']['stats'];

        $this->assertSame(1, $stats['unread']);
    }
}
