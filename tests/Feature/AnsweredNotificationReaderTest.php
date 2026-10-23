<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AnsweredNotificationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A bell notification for a message clears once a person on our team replies
 * in that thread, from the work order page or in Chat Support.
 */
class AnsweredNotificationReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.chatbot.enabled' => true, 'services.chatbot.url' => 'https://chatbot.test', 'services.chatbot.token' => 'test-token', 'services.chatbot.webhook_secret' => 'test-secret', 'services.chatbot.phone' => '+15550000000']);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inbound(WorkOrder $workOrder, string $type, array $attributes = []): Conversation
    {
        $message = Conversation::create($attributes + [
            'work_order_id' => $workOrder->id,
            'conversation_type' => $type,
            'message' => 'Any update?',
            'sender_number' => '+15551111111',
            'receiver_number' => '+15550000000',
            'is_read' => false,
        ]);

        activity()->performedOn($message)->event('work_order_message_received')
            ->withProperties(['work_order_id' => $workOrder->id, 'message' => 'Any update?'])
            ->log('New message');

        return $message;
    }

    private function isRead(Conversation $message): bool
    {
        $activity = Activity::query()->where('subject_type', $message->getMorphClass())->where('subject_id', $message->id)->sole();

        return (bool) ($activity->properties['read'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reply(WorkOrder $workOrder, string $type, array $attributes = []): Conversation
    {
        return Conversation::create($attributes + [
            'work_order_id' => $workOrder->id,
            'conversation_type' => $type,
            'message' => 'On it.',
            'is_read' => true,
        ]);
    }

    public function test_a_reply_marks_the_threads_earlier_messages_read(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $first = $this->inbound($workOrder, 'tenant');
        $second = $this->inbound($workOrder, 'tenant');

        $marked = app(AnsweredNotificationReader::class)->markAnsweredBy($this->reply($workOrder, 'tenant'));

        $this->assertSame(2, $marked);
        $this->assertTrue($this->isRead($first));
        $this->assertTrue($this->isRead($second));
    }

    public function test_other_threads_and_later_messages_stay_unread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $ownerMessage = $this->inbound($workOrder, 'owner');
        $otherWorkOrder = $this->inbound(WorkOrder::factory()->create(), 'tenant');
        $reply = $this->reply($workOrder, 'tenant');
        $later = $this->inbound($workOrder, 'tenant');

        app(AnsweredNotificationReader::class)->markAnsweredBy($reply);

        $this->assertFalse($this->isRead($ownerMessage));
        $this->assertFalse($this->isRead($otherWorkOrder));
        $this->assertFalse($this->isRead($later));
    }

    public function test_a_reply_to_one_owner_leaves_another_owners_message_unread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $first = $this->inbound($workOrder, 'owner', ['owner_id' => 1, 'sender_number' => 'portal']);
        $second = $this->inbound($workOrder, 'owner', ['owner_id' => 2, 'sender_number' => 'portal']);

        app(AnsweredNotificationReader::class)->markAnsweredBy($this->reply($workOrder, 'owner', ['owner_id' => 1]));

        $this->assertTrue($this->isRead($first));
        $this->assertFalse($this->isRead($second));
    }

    public function test_an_owner_approval_is_cleared_by_a_reply(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $approval = Conversation::create(['work_order_id' => $workOrder->id, 'conversation_type' => 'owner', 'message' => 'Approved.', 'sender_number' => 'portal', 'is_read' => false]);
        activity()->performedOn($workOrder)->event('owner_portal_approval')
            ->withProperties(['conversation_id' => $approval->id, 'read' => false])
            ->log('Approved by Owner');

        app(AnsweredNotificationReader::class)->markAnsweredBy($this->reply($workOrder, 'owner'));

        $activity = Activity::query()->where('event', 'owner_portal_approval')->sole();
        $this->assertTrue((bool) $activity->properties['read']);
    }

    public function test_a_coordinator_sending_from_the_work_order_page_clears_the_thread(): void
    {
        Bus::fake();
        Role::findOrCreate('woc', 'web');
        $user = tap(User::factory()->create())->assignRole('woc');
        $workOrder = WorkOrder::factory()->create();
        $message = $this->inbound($workOrder, 'vendor');

        $this->actingAs($user)->post(route('work_order.conversation.send'), [
            'text' => 'On it.',
            'work_order_id' => $workOrder->id,
            'sender_phone_number' => '+15550000000',
            'receiver_phone_number' => '+15551111111',
            'conversation_type' => 'vendor',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->isRead($message));
    }

    public function test_a_staff_reply_in_chat_support_clears_the_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $message = $this->inbound($workOrder, 'tenant');
        $this->linkThread($workOrder);

        $this->hubEvent('agent')->assertNoContent();

        $this->assertTrue($this->isRead($message));
    }

    public function test_a_bot_message_from_chat_support_does_not_clear_the_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $message = $this->inbound($workOrder, 'tenant');
        $this->linkThread($workOrder);

        $this->hubEvent('bot')->assertNoContent();

        $this->assertFalse($this->isRead($message));
    }

    private function linkThread(WorkOrder $workOrder): void
    {
        DB::table('chatbot_threads')->insert(['local_key' => 'test', 'hub_thread_id' => '42', 'work_order_id' => $workOrder->id, 'party' => 'tenant', 'phone' => '+15551111111', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function hubEvent(string $sender): TestResponse
    {
        $timestamp = time();
        $body = json_encode([
            'id' => (string) Str::uuid(),
            'event' => 'message.sent',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['thread' => ['id' => '42'], 'message' => ['id' => '91', 'direction' => 'outbound', 'sender' => $sender, 'sender_name' => 'Lovely', 'body' => 'On it.', 'status' => 'sent', 'twilio_sid' => 'SMtest']],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/chatbot/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_TIMESTAMP' => (string) $timestamp, 'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret')], $body);
    }
}
