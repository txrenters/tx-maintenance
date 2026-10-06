<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * When a tenant or owner message fails on the Chat Support number (most often
 * because they texted STOP), staff can resend it once from the Maintenance
 * number, which goes straight to Twilio and skips the hub.
 */
class ResendFromMaintenanceNumberTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_PHONE = '+15551234567';

    private const CHAT_SUPPORT_NUMBER = '+12812488018';

    private const MAINTENANCE_NUMBER = '+12813787957';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.chatbot.enabled' => true,
            'services.chatbot.url' => 'https://chatbot.test',
            'services.chatbot.token' => 'test-token',
            'services.chatbot.phone' => self::CHAT_SUPPORT_NUMBER,
            'services.twilio.maintenance_from' => self::MAINTENANCE_NUMBER,
        ]);
    }

    public function test_staff_can_resend_an_unsubscribed_tenant_message_from_the_maintenance_number(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('woc'));
        $original = $this->failedMessage(['twilio_error_message' => 'Recipient unsubscribed. Text START to resubscribe.']);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")
            ->assertOk()
            ->assertJsonPath('success', true);

        $new = Conversation::query()->whereKeyNot($original->id)->sole();
        $this->assertSame(self::MAINTENANCE_NUMBER, $new->sender_number);
        $this->assertSame(self::TENANT_PHONE, $new->receiver_number);
        $this->assertSame('Plumber arriving at 2pm', $new->message);
        $this->assertSame('tenant', $new->conversation_type);
        $this->assertSame('outbound', $new->chatbot_direction);
        $this->assertSame('failed', $original->fresh()->twilio_status, 'The original failed row is kept.');

        Queue::assertPushed(SendConversationMessageJob::class, fn (SendConversationMessageJob $job): bool => $job->viaMaintenanceNumber);
    }

    public function test_a_twilio_21610_failure_also_qualifies(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('admin'));
        $original = $this->failedMessage(['twilio_error_code' => '21610', 'twilio_error_message' => 'Attempt to send to unsubscribed recipient']);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")->assertOk();

        Queue::assertPushed(SendConversationMessageJob::class);
    }

    public function test_any_other_failure_also_qualifies(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('woc'));
        $original = $this->failedMessage([
            'twilio_status' => 'undelivered',
            'twilio_error_code' => '30003',
            'twilio_error_message' => 'Unreachable destination handset',
        ]);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")->assertOk();

        Queue::assertPushed(SendConversationMessageJob::class, fn (SendConversationMessageJob $job): bool => $job->viaMaintenanceNumber);
    }

    public function test_a_message_that_did_not_fail_is_rejected(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('woc'));
        $original = $this->failedMessage(['twilio_status' => 'delivered']);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")
            ->assertStatus(422);

        $this->assertDatabaseCount('work_order_conversations', 1);
        Queue::assertNothingPushed();
    }

    public function test_a_vendor_conversation_is_rejected(): void
    {
        Queue::fake();
        $this->actingAs($this->staff('woc'));
        $original = $this->failedMessage([
            'conversation_type' => 'vendor',
            'twilio_error_message' => 'Recipient unsubscribed. Text START to resubscribe.',
        ]);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_user_without_a_staff_role_is_forbidden(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $original = $this->failedMessage(['twilio_error_message' => 'Recipient unsubscribed. Text START to resubscribe.']);

        $this->postJson("/api/conversations/{$original->id}/resend-from-maintenance")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_job_sends_through_twilio_and_never_through_the_hub(): void
    {
        Http::preventStrayRequests();
        $conversation = $this->failedMessage([
            'sender_number' => self::MAINTENANCE_NUMBER,
            'twilio_status' => 'pending',
            'chatbot_direction' => 'outbound',
        ]);

        $this->mock(TwilioService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::TENANT_PHONE, self::MAINTENANCE_NUMBER, 'Plumber arriving at 2pm', null)
            ->andReturnNull();

        (new SendConversationMessageJob(
            self::TENANT_PHONE, self::MAINTENANCE_NUMBER, 'Plumber arriving at 2pm', null, $conversation->id, viaMaintenanceNumber: true,
        ))->handle();

        $this->assertNull($conversation->fresh()->chatbot_thread_id);
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');

        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function failedMessage(array $overrides = []): Conversation
    {
        ServiceStatus::query()->firstOrCreate(['name' => 'Open'], ['description' => 'Open']);

        return Conversation::create($overrides + [
            'message' => 'Plumber arriving at 2pm',
            'conversation_type' => 'tenant',
            'sender_number' => self::CHAT_SUPPORT_NUMBER,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => WorkOrder::factory()->create()->id,
            'chatbot_direction' => 'outbound',
            'twilio_status' => 'failed',
        ]);
    }
}
