<?php

namespace Tests\Feature;

use App\Jobs\SyncPortalConversationToChatbot;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ChatbotHub;
use App\Services\InboundTwilioMessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * After cutover, owners and tenants are texted from the Chat Support number,
 * but some will still text the Maintenance number out of habit. Those replies
 * must land on the right work order, as the right party, and be copied into
 * the same Chat Support thread the outbound message went to.
 */
class MaintenanceNumberReplyTest extends TestCase
{
    use RefreshDatabase;

    private const MAINTENANCE_NUMBER = '+12813787957';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.chatbot.enabled' => true,
            'services.chatbot.url' => 'https://chatbot.test',
            'services.chatbot.token' => 'test-token',
            'services.chatbot.phone' => '+12812488018',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'e.plusthis.com/*' => Http::response([], 200),
            'chatbot.test/api/v1/threads' => Http::sequence()
                ->push(['data' => ['id' => 42]], 201)
                ->push(['data' => ['id' => 43]], 201),
            'chatbot.test/api/v1/threads/*/messages' => Http::sequence()
                ->push(['data' => ['id' => 91, 'status' => 'queued']], 201)
                ->push(['data' => ['id' => 92, 'status' => 'received']], 201)
                ->push(['data' => ['id' => 93, 'status' => 'received']], 201),
        ]);
    }

    private function makeOwner(string $phone, string $propertywareId): Owner
    {
        return Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'name' => 'Olivia Owner',
            'email' => 'o'.uniqid().'@example.com',
            'mobile' => $phone,
            'percentage_ownership' => 50,
            'propertyware_id' => $propertywareId,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeTenant(string $phone, string $propertywareId): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => $phone,
            'propertyware_id' => $propertywareId,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Staff (or an automation) texts the party; after cutover this goes out
     * through Chat Support and opens the hub thread.
     */
    private function sendOutbound(WorkOrder $workOrder, string $type, string $phone, ?int $ownerId = null): Conversation
    {
        $conversation = Conversation::create([
            'work_order_id' => $workOrder->id,
            'conversation_type' => $type,
            'owner_id' => $ownerId,
            'message' => 'Your vendor is scheduled for Monday.',
            'sender_number' => self::MAINTENANCE_NUMBER,
            'receiver_number' => $phone,
            'is_read' => true,
        ]);

        app(ChatbotHub::class)->send($conversation, $conversation->message);

        return $conversation;
    }

    /**
     * The party texts the Maintenance number; returns the stored reply after
     * its Chat Support sync has run.
     */
    private function replyToMaintenanceNumber(string $from, string $sid): Conversation
    {
        $result = app(InboundTwilioMessageProcessor::class)->process([
            'From' => $from,
            'To' => self::MAINTENANCE_NUMBER,
            'Body' => 'Thanks, Monday works',
            'MessageSid' => $sid,
            'NumMedia' => 0,
        ]);

        $this->assertSame('work_order', $result);

        $reply = Conversation::query()->where('twilio_sid', $sid)->firstOrFail();
        Queue::assertPushed(SyncPortalConversationToChatbot::class, fn ($job) => $job->conversationId === $reply->id);
        (new SyncPortalConversationToChatbot($reply->id))->handle(app(ChatbotHub::class));

        return $reply->fresh();
    }

    private function assertCopiedToThread(string $threadId): void
    {
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/threads/{$threadId}/messages")
            && $request['from'] === 'client'
            && $request['body'] === 'Thanks, Monday works'
            && $request['inbound_channel'] === 'sms');
    }

    public function test_a_tenant_reply_lands_on_their_work_order_and_in_the_same_chat_support_thread(): void
    {
        $tenant = $this->makeTenant('5125559999', 'T-1');
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'tenant_id' => $tenant->id]);
        $workOrder->tenants()->attach($tenant->id);
        $this->sendOutbound($workOrder, 'tenant', '+15125559999');

        $reply = $this->replyToMaintenanceNumber('+15125559999', 'SMtenant');

        $this->assertSame($workOrder->id, $reply->work_order_id);
        $this->assertSame('tenant', $reply->conversation_type);
        $this->assertSame('42', $reply->chatbot_thread_id);
        $this->assertCopiedToThread('42');
    }

    public function test_an_owner_reply_lands_on_their_work_order_and_in_the_same_chat_support_thread(): void
    {
        $owner = $this->makeOwner('7135030427', 'O-1');
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $workOrder->owners()->attach($owner->id);
        $this->sendOutbound($workOrder, 'owner', '+17135030427', $owner->id);

        $reply = $this->replyToMaintenanceNumber('+17135030427', 'SMowner');

        $this->assertSame($workOrder->id, $reply->work_order_id);
        $this->assertSame('owner', $reply->conversation_type);
        $this->assertSame('42', $reply->chatbot_thread_id);
        $this->assertCopiedToThread('42');
    }

    public function test_a_reply_from_co_owners_sharing_one_phone_still_reaches_chat_support(): void
    {
        $first = $this->makeOwner('7135030427', 'O-1');
        $second = $this->makeOwner('7135030427', 'O-2');
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $workOrder->owners()->attach([$first->id, $second->id]);
        $this->sendOutbound($workOrder, 'owner', '+17135030427', $first->id);

        $reply = $this->replyToMaintenanceNumber('+17135030427', 'SMcoowner');

        $this->assertSame('owner', $reply->conversation_type);
        $this->assertSame('42', $reply->chatbot_thread_id);
        $this->assertCopiedToThread('42');
    }

    public function test_a_tenant_listed_twice_on_the_roster_still_reaches_chat_support(): void
    {
        // The same PropertyWare contact can land in tenants twice (once as the
        // requester, once from the lease).
        $asRequester = $this->makeTenant('5125559999', 'T-1');
        $fromLease = $this->makeTenant('5125559999', 'T-1');
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'tenant_id' => $asRequester->id]);
        $workOrder->tenants()->attach([$asRequester->id, $fromLease->id]);

        $outbound = Conversation::create([
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'message' => 'Your vendor is scheduled for Monday.',
            'sender_number' => self::MAINTENANCE_NUMBER,
            'receiver_number' => '+15125559999',
            'is_read' => true,
        ]);
        app(ChatbotHub::class)->send($outbound, $outbound->message);

        $reply = $this->replyToMaintenanceNumber('+15125559999', 'SMdupetenant');

        $this->assertSame('42', $reply->chatbot_thread_id);
        $this->assertCopiedToThread('42');
    }

    public function test_a_tenant_with_two_open_work_orders_is_filed_on_the_one_last_texted(): void
    {
        $tenant = $this->makeTenant('5125559999', 'T-1');
        $older = WorkOrder::factory()->create(['status' => 'Open', 'tenant_id' => $tenant->id]);
        $newer = WorkOrder::factory()->create(['status' => 'Open', 'tenant_id' => $tenant->id]);
        $older->tenants()->attach($tenant->id);
        $newer->tenants()->attach($tenant->id);

        $this->sendOutbound($older, 'tenant', '+15125559999');
        $this->travel(5)->minutes();
        $this->sendOutbound($newer, 'tenant', '+15125559999');

        $reply = $this->replyToMaintenanceNumber('+15125559999', 'SMtwowos');

        $this->assertSame($newer->id, $reply->work_order_id);
        $this->assertSame('43', $reply->chatbot_thread_id);
        $this->assertCopiedToThread('43');
    }
}
