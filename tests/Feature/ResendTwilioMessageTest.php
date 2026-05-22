<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendJobberTextMessageJob;
use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResendTwilioMessageTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_PHONE = '+15551234567';

    private const TWILIO_PHONE = '+12816999281';

    public function test_resending_a_failed_work_order_conversation_creates_a_new_row_and_queues_a_send_job(): void
    {
        Queue::fake();

        $workOrder = $this->seedWorkOrder();

        $original = Conversation::create([
            'message' => 'Plumber arriving at 2pm',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
            'twilio_sid' => 'SM_failed',
            'twilio_status' => 'failed',
            'twilio_error_code' => '30003',
            'twilio_error_message' => 'Unknown destination handset',
        ]);

        $response = $this->postJson("/api/conversations/{$original->id}/resend");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('work_order_conversations', 2);

        $original->refresh();
        $this->assertSame('failed', $original->twilio_status, 'Original failed row must be preserved.');

        $new = Conversation::query()->where('id', '!=', $original->id)->first();
        $this->assertSame('Plumber arriving at 2pm', $new->message);
        $this->assertSame(self::TWILIO_PHONE, $new->sender_number);
        $this->assertSame(self::TENANT_PHONE, $new->receiver_number);
        $this->assertSame($workOrder->id, $new->work_order_id);
        $this->assertNull($new->twilio_sid, 'New row should not have a SID until the job runs.');

        Queue::assertPushed(SendConversationMessageJob::class);
    }

    public function test_resending_a_failed_jobber_message_creates_a_new_row_and_queues_a_send_job(): void
    {
        Queue::fake();

        $jobber = $this->seedJobber();

        $original = JobberTextMessage::create([
            'messages' => 'Visit photo attached',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'jobber_id' => $jobber->id,
            'twilio_sid' => 'SM_failed_jobber',
            'twilio_status' => 'undelivered',
            'twilio_error_code' => '30008',
            'twilio_error_message' => 'Unknown error',
        ]);

        $response = $this->postJson("/api/jobber-text-messages/{$original->id}/resend");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('jobber_text_messages', 2);

        $original->refresh();
        $this->assertSame('undelivered', $original->twilio_status, 'Original failed row must be preserved.');

        $new = JobberTextMessage::query()->where('id', '!=', $original->id)->first();
        $this->assertSame('Visit photo attached', $new->messages);
        $this->assertSame(self::TWILIO_PHONE, $new->sender_number);
        $this->assertSame(self::TENANT_PHONE, $new->receiver_number);
        $this->assertSame($jobber->id, $new->jobber_id);
        $this->assertNull($new->twilio_sid, 'New row should not have a SID until the job runs.');

        Queue::assertPushed(SendJobberTextMessageJob::class);
    }

    public function test_resending_rejects_a_message_that_is_not_in_a_failure_state(): void
    {
        Queue::fake();

        $workOrder = $this->seedWorkOrder();

        $delivered = Conversation::create([
            'message' => 'Already delivered',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
            'twilio_sid' => 'SM_delivered',
            'twilio_status' => 'delivered',
        ]);

        $response = $this->postJson("/api/conversations/{$delivered->id}/resend");

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Only failed or undelivered messages can be resent.');

        $this->assertDatabaseCount('work_order_conversations', 1);
        Queue::assertNothingPushed();
    }

    private function seedWorkOrder(): WorkOrder
    {
        ServiceStatus::query()->firstOrCreate(
            ['name' => 'Open'],
            ['description' => 'Open']
        );

        return WorkOrder::factory()->create();
    }

    private function seedJobber(): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'JC_'.uniqid(),
            'name' => 'Test Client',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'JP_'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '123 Test',
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'JJ_'.uniqid(),
            'job_number' => '4242',
            'job_status' => 'open',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }
}
