<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\ServiceStatus;
use App\Models\TwilioPhoneNumber;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImportTwilioInboundMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_PHONE = '+15551234567';

    private const TWILIO_PHONE = '+12816999281';

    private const TWILIO_PHONE_2 = '+12813787957';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'e.plusthis.com/*' => Http::response('{"ok":true}', 200),
        ]);

        TwilioPhoneNumber::query()->create([
            'name' => 'Main',
            'account_sid' => 'AC_test',
            'sid' => 'PN_test_1',
            'phone_number' => self::TWILIO_PHONE,
        ]);

        TwilioPhoneNumber::query()->create([
            'name' => 'Maintenance',
            'account_sid' => 'AC_test',
            'sid' => 'PN_test_2',
            'phone_number' => self::TWILIO_PHONE_2,
        ]);
    }

    public function test_it_imports_an_unseen_inbound_message_into_a_work_order_conversation(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'Hi, your work order is open.',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_new_inbound_1',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'Plumber is here, thanks',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $this->assertDatabaseHas('work_order_conversations', [
            'twilio_sid' => 'SM_new_inbound_1',
            'message' => 'Plumber is here, thanks',
            'sender_number' => self::TENANT_PHONE,
            'receiver_number' => self::TWILIO_PHONE,
            'work_order_id' => $workOrder->id,
        ]);
    }

    public function test_it_imports_an_unseen_inbound_message_into_jobber_text_messages(): void
    {
        $jobber = $this->seedJobber();

        JobberTextMessage::create([
            'messages' => 'Confirming your visit tomorrow.',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'jobber_id' => $jobber->id,
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_new_jobber_inbound',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'Sounds good, see you then',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $this->assertDatabaseHas('jobber_text_messages', [
            'messages' => 'Sounds good, see you then',
            'sender_number' => self::TENANT_PHONE,
            'receiver_number' => self::TWILIO_PHONE,
            'jobber_id' => $jobber->id,
            'twilio_sid' => 'SM_new_jobber_inbound',
        ]);
    }

    public function test_it_skips_messages_whose_sid_is_already_stored(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'previously stored body',
            'conversation_type' => 'tenant',
            'sender_number' => self::TENANT_PHONE,
            'receiver_number' => self::TWILIO_PHONE,
            'work_order_id' => $workOrder->id,
            'twilio_sid' => 'SM_already_here',
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_already_here',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'previously stored body',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $this->assertSame(1, Conversation::query()->where('twilio_sid', 'SM_already_here')->count());
    }

    public function test_it_skips_messages_that_fuzzy_match_a_row_without_a_twilio_sid(): void
    {
        $jobber = $this->seedJobber();

        $dateSent = CarbonImmutable::now()->subMinutes(15);

        // Existing inbound saved by the OLD webhook (no twilio_sid populated).
        JobberTextMessage::create([
            'messages' => 'thanks for the update',
            'sender_number' => self::TENANT_PHONE,
            'receiver_number' => self::TWILIO_PHONE,
            'jobber_id' => $jobber->id,
            'created_at' => $dateSent->addMinutes(2),
            'updated_at' => $dateSent->addMinutes(2),
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_fuzzy_dup',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'thanks for the update',
                'date_sent' => $dateSent->toDateTime(),
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        // Still exactly one row — the fuzzy dedup skipped the import.
        $this->assertSame(1, JobberTextMessage::query()
            ->where('sender_number', self::TENANT_PHONE)
            ->where('receiver_number', self::TWILIO_PHONE)
            ->count());
        $this->assertSame(0, JobberTextMessage::query()->where('twilio_sid', 'SM_fuzzy_dup')->count());
    }

    public function test_imported_work_order_message_logs_notification_activity(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'thread seed',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_notify_wo',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'tenant reply visible in bell',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $activity = Activity::query()->where('event', 'work_order_message_received')->latest('id')->first();
        $this->assertNotNull($activity, 'Notification activity log entry must be created for imported inbound.');
        $this->assertSame(self::TWILIO_PHONE, $activity->properties['receiverNumber']);
        $this->assertSame(self::TENANT_PHONE, $activity->properties['senderNumber']);
        $this->assertSame('tenant reply visible in bell', $activity->properties['message']);
        $this->assertSame($workOrder->id, $activity->properties['work_order_id']);
        $this->assertFalse($activity->properties['read'] ?? false);
    }

    public function test_imported_jobber_message_logs_notification_activity(): void
    {
        $jobber = $this->seedJobber();

        JobberTextMessage::create([
            'messages' => 'thread seed',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'jobber_id' => $jobber->id,
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_notify_jobber',
                'from' => self::TENANT_PHONE,
                'to' => self::TWILIO_PHONE,
                'body' => 'jobber reply visible in bell',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $activity = Activity::query()->where('event', 'job_message_received')->latest('id')->first();
        $this->assertNotNull($activity, 'Notification activity log entry must be created for imported jobber inbound.');
        $this->assertSame(self::TWILIO_PHONE, $activity->properties['receiverNumber']);
        $this->assertSame(self::TENANT_PHONE, $activity->properties['senderNumber']);
        $this->assertSame('jobber reply visible in bell', $activity->properties['message']);
        $this->assertSame($jobber->id, $activity->properties['job_id']);
    }

    public function test_webhook_still_stores_inbound_after_refactor(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'Outgoing tenant ping',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
        ]);

        $response = $this->post('/api/twilio/webhook', [
            'MessageSid' => 'SM_webhook_inbound',
            'From' => self::TENANT_PHONE,
            'To' => self::TWILIO_PHONE,
            'Body' => 'webhook delivered reply',
            'NumMedia' => '0',
        ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('work_order_conversations', [
            'twilio_sid' => 'SM_webhook_inbound',
            'message' => 'webhook delivered reply',
            'work_order_id' => $workOrder->id,
        ]);
    }

    public function test_it_skips_outbound_messages_returned_by_twilio(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'hello tenant',
            'conversation_type' => 'tenant',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
        ]);

        $this->bindFakeTwilioWithMessages([
            $this->makeMessage([
                'sid' => 'SM_outbound_should_skip',
                'from' => self::TWILIO_PHONE,
                'to' => self::TENANT_PHONE,
                'body' => 'follow up from us',
                'direction' => 'outbound-api',
            ]),
        ]);

        $this->artisan('twilio:import-inbound-messages', ['--lookback' => 60])
            ->assertSuccessful();

        $this->assertSame(0, Conversation::query()->where('twilio_sid', 'SM_outbound_should_skip')->count());
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

    /**
     * @param  array<int, object>  $messages
     */
    private function bindFakeTwilioWithMessages(array $messages): void
    {
        $fake = new class($messages) extends TwilioService
        {
            public function __construct(private array $stubbed)
            {
                // bypass parent constructor (no Twilio client needed for tests)
            }

            public function streamInboundMessagesTo(string $toNumber, \DateTimeInterface $dateSentAfter, int $limit = 200): iterable
            {
                return array_values(array_filter(
                    $this->stubbed,
                    fn ($m) => (string) $m->to === $toNumber
                ));
            }

            public function fetchMessageMedia(string $sid): array
            {
                return [];
            }
        };

        $this->app->instance(TwilioService::class, $fake);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMessage(array $overrides): object
    {
        return (object) array_merge([
            'sid' => 'SM_'.uniqid(),
            'accountSid' => 'AC_test',
            'from' => self::TENANT_PHONE,
            'to' => self::TWILIO_PHONE,
            'body' => 'hello',
            'direction' => 'inbound',
            'numMedia' => '0',
            'numSegments' => '1',
            'messagingServiceSid' => null,
            'dateSent' => CarbonImmutable::now()->subMinutes(5)->toDateTime(),
        ], $overrides);
    }
}
