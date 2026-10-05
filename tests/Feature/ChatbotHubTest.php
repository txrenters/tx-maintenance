<?php

namespace Tests\Feature;

use App\Jobs\ImportChatbotMedia;
use App\Jobs\SendConversationMessageJob;
use App\Jobs\SyncPortalConversationToChatbot;
use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\ChatbotHub;
use App\Services\InboundTwilioMessageProcessor;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatbotHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.chatbot.enabled' => true, 'services.chatbot.url' => 'https://chatbot.test', 'services.chatbot.token' => 'test-token', 'services.chatbot.webhook_secret' => 'test-secret', 'services.chatbot.phone' => '+15550000000']);
        Http::preventStrayRequests();
    }

    public function test_outbound_retries_reuse_the_thread_and_message(): void
    {
        Http::fake([
            'chatbot.test/api/v1/threads' => Http::response(['data' => ['id' => 42]], 201),
            'chatbot.test/api/v1/threads/42/messages' => Http::response(['data' => ['id' => 91, 'status' => 'pending']], 202),
        ]);
        $message = $this->conversation();
        $hub = app(ChatbotHub::class);
        $hub->send($message, 'Hello');
        $hub->send($message, 'Hello');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://chatbot.test/api/v1/threads/42/messages'
            && $request['sender_email'] === 'staff@example.com'
            && $request['idempotency_key'] === 'tx-maintenance:conversation:'.$message->id.':0');
        $this->assertSame('outbound', $message->fresh()->chatbot_direction);
    }

    public function test_vendor_and_disabled_conversations_are_not_routed_to_chatbot(): void
    {
        $hub = app(ChatbotHub::class);
        $message = $this->conversation();
        $message->conversation_type = 'vendor';
        $this->assertFalse($hub->handles($message));
        $message->conversation_type = 'owner';
        config(['services.chatbot.enabled' => false]);
        $this->assertFalse($hub->handles($message));
    }

    public function test_supported_photos_use_mms_and_documents_use_links(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.jpg', 'small image');
        $message = $this->conversation();
        $photo = $message->media()->create(['original_url' => '', 'local_path' => 'photo.jpg', 'content_type' => 'image/jpeg', 'file_name' => 'photo.jpg']);
        Http::fake([
            'chatbot.test/api/v1/threads' => Http::response(['data' => ['id' => 42]], 201),
            'chatbot.test/api/v1/threads/42/messages' => Http::sequence()->push(['data' => ['id' => 91, 'status' => 'pending']], 202)->push(['data' => ['id' => 92, 'status' => 'pending']], 202),
        ]);
        app(ChatbotHub::class)->send($message, 'Photo', [$photo->public_url, 'https://files.test/report.pdf']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['media_url'] === $photo->public_url && $request['body'] === 'Photo');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['body'] === 'https://files.test/report.pdf' && $request['media_url'] === null);
    }

    public function test_hub_failure_never_falls_back_to_twilio(): void
    {
        Http::fake(['chatbot.test/*' => Http::response([], 503)]);
        $this->mock(TwilioService::class)->shouldNotReceive('sendMessage');
        $message = $this->conversation();
        $this->expectException(RequestException::class);
        (new SendConversationMessageJob($message->receiver_number, $message->sender_number, 'Hello', null, $message->id))->handle();
    }

    public function test_old_number_reply_is_synced_as_incoming_sms(): void
    {
        $message = $this->conversation();
        $message->update(['sender_number' => '+15551111111', 'receiver_number' => '+15550000001', 'twilio_sid' => 'SMlegacy', 'chatbot_direction' => 'inbound']);
        Http::fake([
            'chatbot.test/api/v1/threads' => Http::response(['data' => ['id' => 42]], 201),
            'chatbot.test/api/v1/threads/42/messages' => Http::response(['data' => ['id' => 91]], 201),
        ]);
        app(ChatbotHub::class)->send($message, 'Yes');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['from'] === 'client' && $request['inbound_channel'] === 'sms' && $request['inbound_twilio_sid'] === 'SMlegacy');
        $this->assertSame('SMlegacy', $message->fresh()->twilio_sid);
    }

    public function test_unsigned_and_expired_events_are_rejected(): void
    {
        $this->postJson('/api/chatbot/events', [])->assertForbidden();
        $this->event([], now()->subMinutes(10)->timestamp)->assertForbidden();
    }

    public function test_chatbot_attachments_are_imported_once(): void
    {
        Storage::fake('local');
        $message = $this->conversation();
        $message->update(['chatbot_direction' => 'outbound']);
        Http::fake(['chatbot.test/storage/photo.jpg' => Http::response('image', 200, ['Content-Type' => 'image/jpeg'])]);
        $job = new ImportChatbotMedia($message->id, ['https://chatbot.test/storage/photo.jpg']);
        $job->handle();
        $job->handle();
        $this->assertCount(1, $message->fresh()->media);
        $this->assertTrue((bool) $message->fresh()->is_mms);
        Http::assertSentCount(1);
    }

    public function test_old_number_webhook_queues_the_reply_for_sync(): void
    {
        Queue::fake();
        Http::fake(['e.plusthis.com/*' => Http::response([], 200)]);
        $message = $this->conversation();
        $result = app(InboundTwilioMessageProcessor::class)->process([
            'From' => $message->receiver_number, 'To' => $message->sender_number,
            'Body' => 'Yes, thank you', 'MessageSid' => 'SMoldnumber', 'NumMedia' => 0,
        ]);
        $this->assertSame('work_order', $result);
        Queue::assertPushed(SyncPortalConversationToChatbot::class);
        $this->assertDatabaseHas('work_order_conversations', ['twilio_sid' => 'SMoldnumber', 'chatbot_direction' => 'inbound', 'work_order_id' => $message->work_order_id]);
    }

    public function test_replies_are_stored_once_and_do_not_send_a_message(): void
    {
        $original = $this->conversation();
        $this->link($original);
        $event = $this->payload('message.received', 'received', now()->toIso8601String());
        $event['data']['message']['direction'] = 'inbound';
        $this->event($event)->assertNoContent();
        $this->event($event)->assertNoContent();
        $this->assertDatabaseCount('work_order_conversations', 2);
        $this->assertDatabaseHas('work_order_conversations', ['message' => 'Hello', 'sender_number' => '+15551111111', 'chatbot_direction' => 'inbound']);
        Http::assertNothingSent();
    }

    public function test_status_events_update_the_existing_message_without_regressing(): void
    {
        $message = $this->conversation();
        $this->link($message);
        DB::table('chatbot_message_deliveries')->insert(['conversation_id' => $message->id, 'hub_message_id' => '91', 'created_at' => now(), 'updated_at' => now()]);
        $this->event($this->payload('message.status', 'delivered', now()->toIso8601String()))->assertNoContent();
        $this->event($this->payload('message.status', 'sent', now()->subMinute()->toIso8601String()))->assertNoContent();
        $this->assertSame('delivered', $message->fresh()->twilio_status);
        $this->assertDatabaseCount('work_order_conversations', 1);
    }

    public function test_test_mode_refuses_numbers_that_are_not_allowlisted(): void
    {
        // A pre-production copy holds real tenant/owner numbers; in test mode
        // the support app must only ever be asked to text allowlisted phones.
        config(['services.chatbot.test_mode' => true, 'services.chatbot.allowed_phones' => '+1 (555) 222-2222']);
        Http::fake();
        $this->mock(TwilioService::class)->shouldNotReceive('sendMessage');
        $message = $this->conversation();

        try {
            (new SendConversationMessageJob($message->receiver_number, $message->sender_number, 'Hello', null, $message->id))->handle();
            $this->fail('A number that is not allowlisted was sent to the support app.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('test mode', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_test_mode_with_an_empty_allowlist_sends_nothing(): void
    {
        config(['services.chatbot.test_mode' => true, 'services.chatbot.allowed_phones' => '']);
        Http::fake();

        $this->expectException(\RuntimeException::class);

        try {
            app(ChatbotHub::class)->send($this->conversation(), 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_test_mode_sends_allowlisted_numbers_whatever_their_format(): void
    {
        config(['services.chatbot.test_mode' => true, 'services.chatbot.allowed_phones' => '+1 555-999-0000, (555) 111-1111']);
        Http::fake([
            'chatbot.test/api/v1/threads' => Http::response(['data' => ['id' => 42]], 201),
            'chatbot.test/api/v1/threads/42/messages' => Http::response(['data' => ['id' => 91, 'status' => 'pending']], 202),
        ]);

        app(ChatbotHub::class)->send($this->conversation(), 'Hello');

        Http::assertSentCount(2);
    }

    private function conversation(): Conversation
    {
        return Conversation::create(['work_order_id' => WorkOrder::factory()->create()->id, 'conversation_type' => 'tenant', 'message' => 'Hello', 'sender_number' => '+15550000000', 'receiver_number' => '+15551111111', 'chatbot_sender_name' => 'Staff', 'chatbot_sender_email' => 'staff@example.com']);
    }

    private function link(Conversation $message): void
    {
        DB::table('chatbot_threads')->insert(['local_key' => 'test', 'hub_thread_id' => '42', 'work_order_id' => $message->work_order_id, 'party' => 'tenant', 'phone' => '+15551111111', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(string $event, string $status, string $at): array
    {
        return ['id' => (string) Str::uuid(), 'event' => $event, 'occurred_at' => $at, 'data' => ['thread' => ['id' => '42'], 'message' => ['id' => '91', 'direction' => 'outbound', 'body' => 'Hello', 'status' => $status, 'twilio_sid' => 'SMtest']]];
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= time();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/chatbot/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_TIMESTAMP' => (string) $timestamp, 'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret')], $body);
    }
}
