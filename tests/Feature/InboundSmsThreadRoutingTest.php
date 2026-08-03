<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TwilioPhoneNumber;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Which work order an inbound SMS lands on.
 *
 * The outbound "from" number belongs to a coordinator rather than to a work
 * order, so a tenant with two open work orders under the same coordinator has an
 * identical phone pair on both. These tests pin the tie-breaks that decide
 * between them.
 */
class InboundSmsThreadRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_PHONE = '+15551234567';

    private const VENDOR_PHONE = '+15559990000';

    private const TWILIO_PHONE = '+12816999281';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            // The processor forwards every inbound payload here unconditionally.
            'e.plusthis.com/*' => Http::response('{"ok":true}', 200),
        ]);

        TwilioPhoneNumber::query()->create([
            'name' => 'Main',
            'account_sid' => 'AC_test',
            'sid' => 'PN_test_1',
            'phone_number' => self::TWILIO_PHONE,
        ]);
    }

    public function test_reply_lands_on_the_most_recently_texted_open_work_order(): void
    {
        $older = $this->seedWorkOrder();
        $newer = $this->seedWorkOrder();

        $this->seedOutbound($older, 'tenant', self::TENANT_PHONE, now()->subHours(2));
        $this->seedOutbound($newer, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        $this->postInbound('The plumber never showed up', 'SM_route_1')->assertNoContent();

        $stored = $this->storedMessage('SM_route_1');

        $this->assertSame($newer->id, (int) $stored->work_order_id);
        $this->assertSame('tenant', $stored->conversation_type);
        $this->assertSame(1, $this->messageCountOn($older), 'The older work order should have kept only its seeded message.');
    }

    public function test_an_earlier_reply_on_another_work_order_does_not_capture_later_ones(): void
    {
        // A message the tenant sent us is not evidence of what they are replying
        // to now. Ranking by activity in either direction would let one misrouted
        // reply pull every later reply onto the same wrong work order.
        $asked = $this->seedWorkOrder();
        $other = $this->seedWorkOrder();

        $this->seedOutbound($other, 'tenant', self::TENANT_PHONE, now()->subHours(3));
        $this->seedOutbound($asked, 'tenant', self::TENANT_PHONE, now()->subMinutes(30));
        $this->seedInbound($other, 'tenant', self::TENANT_PHONE, now()->subMinutes(5));

        $this->postInbound('Sorry, yes — Tuesday works', 'SM_route_1b')->assertNoContent();

        $this->assertSame($asked->id, (int) $this->storedMessage('SM_route_1b')->work_order_id);
    }

    public function test_a_ref_footer_overrides_recency(): void
    {
        $referenced = $this->seedWorkOrder();
        $newer = $this->seedWorkOrder();

        $this->seedOutbound($referenced, 'tenant', self::TENANT_PHONE, now()->subHours(2));
        $this->seedOutbound($newer, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        $this->postInbound("Yes that works\n(Ref: WO#{$referenced->work_order_no})", 'SM_route_2')->assertNoContent();

        $this->assertSame($referenced->id, (int) $this->storedMessage('SM_route_2')->work_order_id);
    }

    public function test_ref_matching_tolerates_case_and_spacing(): void
    {
        $referenced = $this->seedWorkOrder();
        $newer = $this->seedWorkOrder();

        $this->seedOutbound($referenced, 'tenant', self::TENANT_PHONE, now()->subHours(2));
        $this->seedOutbound($newer, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        $this->postInbound("ok ref: wo#{$referenced->work_order_no}", 'SM_route_3a')->assertNoContent();
        $this->postInbound("ok (Ref:  WO# {$referenced->work_order_no})", 'SM_route_3b')->assertNoContent();

        $this->assertSame($referenced->id, (int) $this->storedMessage('SM_route_3a')->work_order_id);
        $this->assertSame($referenced->id, (int) $this->storedMessage('SM_route_3b')->work_order_id);
    }

    public function test_ref_resolves_a_work_order_that_has_no_work_order_no_by_id(): void
    {
        // Senders build the footer as work_order_no ?? id, so an unnumbered work
        // order quotes its primary key instead.
        $unnumbered = $this->seedWorkOrder(['work_order_no' => null]);
        $newer = $this->seedWorkOrder();

        $this->seedOutbound($unnumbered, 'tenant', self::TENANT_PHONE, now()->subHours(2));
        $this->seedOutbound($newer, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        $this->postInbound("thanks (Ref: WO#{$unnumbered->id})", 'SM_route_4')->assertNoContent();

        $this->assertSame($unnumbered->id, (int) $this->storedMessage('SM_route_4')->work_order_id);
    }

    public function test_reply_is_not_stamped_with_a_vendor_thread_type(): void
    {
        $workOrder = $this->seedWorkOrder();

        $this->seedOutbound($workOrder, 'tenant', self::TENANT_PHONE, now()->subHour());
        $this->seedOutbound($workOrder, 'vendor', self::VENDOR_PHONE, now()->subMinute());

        $this->postInbound('I will be home after 5', 'SM_route_5')->assertNoContent();

        $stored = $this->storedMessage('SM_route_5');

        $this->assertSame('tenant', $stored->conversation_type);
        $this->assertSame($workOrder->id, (int) $stored->work_order_id);
    }

    public function test_a_vendor_reply_lands_on_the_vendor_thread(): void
    {
        $workOrder = $this->seedWorkOrder();

        $this->seedOutbound($workOrder, 'tenant', self::TENANT_PHONE, now()->subHour());
        $this->seedOutbound($workOrder, 'vendor', self::VENDOR_PHONE, now()->subMinute());

        $this->postInbound('On my way', 'SM_route_6', self::VENDOR_PHONE)->assertNoContent();

        $stored = $this->storedMessage('SM_route_6');

        $this->assertSame('vendor', $stored->conversation_type);
        $this->assertSame($workOrder->id, (int) $stored->work_order_id);
    }

    public function test_an_open_work_order_wins_over_a_more_recently_texted_closed_one(): void
    {
        $closed = $this->seedWorkOrder(['status' => 'Closed']);
        $open = $this->seedWorkOrder();

        $this->seedOutbound($closed, 'tenant', self::TENANT_PHONE, now()->subMinutes(5));
        $this->seedOutbound($open, 'tenant', self::TENANT_PHONE, now()->subHours(3));

        $this->postInbound('Any update?', 'SM_route_7')->assertNoContent();

        $this->assertSame($open->id, (int) $this->storedMessage('SM_route_7')->work_order_id);
    }

    public function test_a_reply_to_a_closed_work_order_is_still_stored_when_no_open_one_exists(): void
    {
        $closed = $this->seedWorkOrder(['status' => 'Closed']);

        $this->seedOutbound($closed, 'tenant', self::TENANT_PHONE, now()->subMinutes(5));

        $this->postInbound('It broke again', 'SM_route_8')->assertNoContent();

        $this->assertSame($closed->id, (int) $this->storedMessage('SM_route_8')->work_order_id);
    }

    public function test_created_at_ties_resolve_to_the_newest_row_deterministically(): void
    {
        $first = $this->seedWorkOrder();
        $second = $this->seedWorkOrder();

        $tie = now()->subMinutes(30);
        $this->seedOutbound($first, 'tenant', self::TENANT_PHONE, $tie);
        $this->seedOutbound($second, 'tenant', self::TENANT_PHONE, $tie);

        $this->postInbound('reply one', 'SM_route_9a')->assertNoContent();
        $this->postInbound('reply two', 'SM_route_9b')->assertNoContent();

        $this->assertSame($second->id, (int) $this->storedMessage('SM_route_9a')->work_order_id);
        $this->assertSame($second->id, (int) $this->storedMessage('SM_route_9b')->work_order_id);
    }

    public function test_matching_tolerates_phone_number_formatting_drift(): void
    {
        $workOrder = $this->seedWorkOrder();

        Conversation::create([
            'message' => 'stored before numbers were normalised',
            'conversation_type' => 'tenant',
            'sender_number' => '(281) 699-9281',
            'receiver_number' => '555-123-4567',
            'work_order_id' => $workOrder->id,
        ]);

        $this->postInbound('got it', 'SM_route_10')->assertNoContent();

        $this->assertSame($workOrder->id, (int) $this->storedMessage('SM_route_10')->work_order_id);
    }

    public function test_a_blank_conversation_type_row_is_skipped_rather_than_dropping_the_message(): void
    {
        $workOrder = $this->seedWorkOrder();

        $this->seedOutbound($workOrder, 'tenant', self::TENANT_PHONE, now()->subHour());

        // conversation_type is NOT NULL, so an unusable type reaches the table as
        // an empty string — the request validation has no `in:` rule.
        Conversation::create([
            'message' => 'typeless row',
            'conversation_type' => '',
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => self::TENANT_PHONE,
            'work_order_id' => $workOrder->id,
            'created_at' => now()->subMinute(),
        ]);

        $this->postInbound('still here', 'SM_route_11')->assertNoContent();

        $stored = $this->storedMessage('SM_route_11');

        $this->assertSame($workOrder->id, (int) $stored->work_order_id);
        $this->assertSame('tenant', $stored->conversation_type);
    }

    public function test_an_ambiguous_phone_pair_logs_the_candidate_work_order_ids(): void
    {
        $older = $this->seedWorkOrder();
        $newer = $this->seedWorkOrder();

        $this->seedOutbound($older, 'tenant', self::TENANT_PHONE, now()->subHours(2));
        $this->seedOutbound($newer, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        Log::spy();

        $this->postInbound('which one is this about', 'SM_route_12')->assertNoContent();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($older, $newer): bool {
                return str_contains($message, 'Inbound SMS thread ambiguous')
                    && in_array($older->id, $context['candidate_work_order_ids'], true)
                    && in_array($newer->id, $context['candidate_work_order_ids'], true)
                    && $context['work_order_id'] === $newer->id;
            })
            ->once();
    }

    public function test_an_unambiguous_reply_logs_no_ambiguity_warning(): void
    {
        $workOrder = $this->seedWorkOrder();

        $this->seedOutbound($workOrder, 'tenant', self::TENANT_PHONE, now()->subMinutes(10));

        Log::spy();

        $this->postInbound('all fixed thanks', 'SM_route_13')->assertNoContent();

        Log::shouldNotHaveReceived('warning', ['Inbound SMS thread ambiguous — multiple work orders share this phone pair']);
    }

    public function test_a_reply_from_an_unknown_number_is_still_unmatched(): void
    {
        $this->seedWorkOrder();

        $this->postInbound('wrong number', 'SM_route_14')->assertNoContent();

        $this->assertDatabaseCount('work_order_conversations', 0);
    }

    public function test_a_ref_resolves_the_thread_from_the_work_orders_own_tenant(): void
    {
        // No conversation history at all, so the thread can only come from the
        // work order's own parties.
        $tenant = Tenants::factory()->create(['mobile_phone' => '5551234567']);
        $workOrder = $this->seedWorkOrder(['tenant_id' => $tenant->id]);

        $this->postInbound("Here are the photos (Ref: WO#{$workOrder->work_order_no})", 'SM_route_15')->assertNoContent();

        $stored = $this->storedMessage('SM_route_15');

        $this->assertSame($workOrder->id, (int) $stored->work_order_id);
        $this->assertSame('tenant', $stored->conversation_type);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedWorkOrder(array $attributes = []): WorkOrder
    {
        ServiceStatus::query()->firstOrCreate(
            ['name' => 'Open'],
            ['description' => 'Open']
        );

        return WorkOrder::factory()->create($attributes);
    }

    /**
     * A message we sent from the coordinator's Twilio number to a person.
     */
    private function seedOutbound(WorkOrder $workOrder, string $type, string $recipient, ?Carbon $at = null): Conversation
    {
        return Conversation::create([
            'message' => 'outbound seed',
            'conversation_type' => $type,
            'sender_number' => self::TWILIO_PHONE,
            'receiver_number' => $recipient,
            'work_order_id' => $workOrder->id,
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * A message a person sent us, as the portal and earlier replies leave behind.
     */
    private function seedInbound(WorkOrder $workOrder, string $type, string $sender, ?Carbon $at = null): Conversation
    {
        return Conversation::create([
            'message' => 'inbound seed',
            'conversation_type' => $type,
            'sender_number' => $sender,
            'receiver_number' => self::TWILIO_PHONE,
            'work_order_id' => $workOrder->id,
            'created_at' => $at ?? now(),
        ]);
    }

    private function postInbound(string $body, string $sid, string $from = self::TENANT_PHONE, string $to = self::TWILIO_PHONE): TestResponse
    {
        return $this->post('/api/twilio/webhook', [
            'MessageSid' => $sid,
            'From' => $from,
            'To' => $to,
            'Body' => $body,
            'NumMedia' => '0',
        ]);
    }

    private function storedMessage(string $sid): Conversation
    {
        $stored = Conversation::query()->where('twilio_sid', $sid)->first();

        $this->assertNotNull($stored, "Inbound message {$sid} was not stored.");

        return $stored;
    }

    private function messageCountOn(WorkOrder $workOrder): int
    {
        return Conversation::query()->where('work_order_id', $workOrder->id)->count();
    }
}
