<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PortalMessageHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = '+15550000000';

    private WorkOrder $workOrder;

    private Tenants $tenant;

    private Tenants $coTenant;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.portal.token' => 'portal-token']);

        $this->tenant = Tenants::factory()->create(['propertyware_id' => '1001', 'mobile_phone' => '5551110001', 'home_phone' => null]);
        $this->coTenant = Tenants::factory()->create(['propertyware_id' => '1002', 'mobile_phone' => '5551110002', 'home_phone' => null]);
        $this->owner = Owner::factory()->create(['propertyware_id' => '2001', 'mobile' => '5552220001', 'phone' => null]);

        $this->workOrder = WorkOrder::factory()->create(['tenant_id' => $this->tenant->id, 'propertyware_id' => '9001', 'work_order_no' => '1234']);
        $this->workOrder->tenants()->attach([$this->tenant->id, $this->coTenant->id]);
        $this->workOrder->owners()->attach($this->owner->id);
    }

    public function test_a_tenant_sees_only_their_own_texts_on_their_work_orders(): void
    {
        $toTenant = $this->message('tenant', self::OFFICE, '+15551110001', 'We will be there Tuesday.', '2026-01-02 10:00:00');
        $fromTenant = $this->message('tenant', '+15551110001', self::OFFICE, 'Thanks, see you then.', '2026-01-02 11:00:00');
        $this->message('tenant', self::OFFICE, '+15551110002', 'Co-tenant only.', '2026-01-02 12:00:00');
        $this->message('owner', self::OFFICE, '+15552220001', 'Owner only.', '2026-01-02 13:00:00');
        $this->message('vendor', self::OFFICE, '+15551110001', 'Vendor thread.', '2026-01-02 14:00:00');

        $response = $this->history('1001', 'tenant')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_order.propertyware_id', '9001')
            ->assertJsonPath('data.0.work_order.number', '1234')
            ->assertJsonCount(2, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.id', $toTenant->id)
            ->assertJsonPath('data.0.messages.0.from', 'staff')
            ->assertJsonPath('data.0.messages.0.to_you', true)
            ->assertJsonPath('data.0.messages.1.id', $fromTenant->id)
            ->assertJsonPath('data.0.messages.1.from', 'client');
    }

    public function test_a_co_tenant_on_the_same_work_order_sees_only_theirs(): void
    {
        $this->message('tenant', self::OFFICE, '+15551110001', 'First tenant only.');
        $theirs = $this->message('tenant', self::OFFICE, '+15551110002', 'Co-tenant only.');

        $this->history('1002', 'tenant')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.id', $theirs->id);
    }

    public function test_a_work_order_the_person_is_not_on_is_left_out_even_for_their_number(): void
    {
        $other = WorkOrder::factory()->create();
        Conversation::create(['work_order_id' => $other->id, 'conversation_type' => 'tenant', 'message' => 'Someone else.', 'sender_number' => self::OFFICE, 'receiver_number' => '+15551110001']);

        $this->history('1001', 'tenant')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_texts_the_support_app_already_holds_are_left_out(): void
    {
        $this->message('tenant', self::OFFICE, '+15551110001', 'Synced.')->update(['chatbot_thread_id' => '42']);
        $delivered = $this->message('tenant', self::OFFICE, '+15551110001', 'Delivered by the hub.');
        DB::table('chatbot_message_deliveries')->insert(['conversation_id' => $delivered->id, 'idempotency_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);

        $this->history('1001', 'tenant')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_an_owner_sees_their_texts_but_not_another_owners_or_the_tenants(): void
    {
        $coOwner = Owner::factory()->create(['propertyware_id' => '2002', 'mobile' => '5552220001', 'phone' => null]);
        $this->workOrder->owners()->attach($coOwner->id);
        $theirs = $this->message('owner', self::OFFICE, '+15552220001', 'Estimate attached.', '2026-01-02 10:00:00', ['owner_id' => $this->owner->id]);
        $this->message('owner', self::OFFICE, '+15552220001', 'For the co-owner.', '2026-01-02 11:00:00', ['owner_id' => $coOwner->id]);
        $this->message('tenant', self::OFFICE, '+15551110001', 'Tenant only.');

        $this->history('2001', 'owner')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.id', $theirs->id);
    }

    public function test_a_work_orders_page_shows_everyone_on_its_side_and_whose_each_text_was(): void
    {
        $this->message('tenant', self::OFFICE, '+15551110001', 'To the first tenant.', '2026-01-02 10:00:00');
        $this->message('tenant', '+15551110002', self::OFFICE, 'From the co-tenant.', '2026-01-02 11:00:00');
        $this->message('tenant', self::OFFICE, '+15551110002', 'To the co-tenant.', '2026-01-02 12:00:00');
        $this->message('tenant', self::OFFICE, '+17135550123', 'To a number no one on the work order has.', '2026-01-02 13:00:00');
        $this->message('owner', self::OFFICE, '+15552220001', 'Owner only.', '2026-01-02 14:00:00');
        $this->message('vendor', self::OFFICE, '+15551110001', 'Vendor thread.', '2026-01-02 15:00:00');

        $this->history('1001', 'tenant', '9001')
            ->assertOk()
            ->assertJsonCount(3, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.from', 'staff')
            ->assertJsonPath('data.0.messages.0.to_you', true)
            ->assertJsonPath('data.0.messages.1.from', 'other')
            ->assertJsonPath('data.0.messages.2.from', 'staff')
            ->assertJsonPath('data.0.messages.2.to_you', false);
    }

    public function test_a_reporter_outside_the_lease_household_and_the_household_never_see_each_others_texts(): void
    {
        $reporter = Tenants::factory()->create(['propertyware_id' => '1003', 'mobile_phone' => '5551110003', 'home_phone' => null]);
        $this->workOrder->update(['tenant_id' => $reporter->id]);
        $this->message('tenant', '+15551110003', self::OFFICE, 'From the former tenant.', '2026-01-02 10:00:00');
        $this->message('tenant', '+15551110001', self::OFFICE, 'From the household.', '2026-01-02 11:00:00');

        $this->history('1001', 'tenant', '9001')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.body', 'From the household.');

        $this->history('1003', 'tenant', '9001')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.messages')
            ->assertJsonPath('data.0.messages.0.body', 'From the former tenant.');
    }

    public function test_a_work_order_the_person_is_not_on_shows_nothing(): void
    {
        $other = WorkOrder::factory()->create(['propertyware_id' => '9002']);
        Conversation::create(['work_order_id' => $other->id, 'conversation_type' => 'tenant', 'message' => 'Someone else.', 'sender_number' => self::OFFICE, 'receiver_number' => '+15551110001']);

        $this->history('1001', 'tenant', '9002')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_entry_codes_are_masked(): void
    {
        $this->message('tenant', self::OFFICE, '+15551110001', 'The lockbox code is 4821 and the gate is #1234.');

        $this->history('1001', 'tenant')
            ->assertOk()
            ->assertJsonPath('data.0.messages.0.body', 'The lockbox code is •••• and the gate is #••••.');
    }

    public function test_only_the_portal_token_gets_in(): void
    {
        $this->getJson('/api/portal/v1/message-history?contact=1001&party=tenant')->assertUnauthorized();
        $this->getJson('/api/portal/v1/message-history?contact=1001&party=tenant', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        config(['services.portal.token' => null]);
        $this->history('1001', 'tenant')->assertUnauthorized();
    }

    public function test_vendors_cannot_be_asked_for(): void
    {
        $this->history('1001', 'vendor')->assertUnprocessable()->assertJsonValidationErrors('party');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(string $type, string $from, string $to, string $body, string $at = '2026-01-01 09:00:00', array $attributes = []): Conversation
    {
        $message = Conversation::create(['work_order_id' => $this->workOrder->id, 'conversation_type' => $type, 'message' => $body, 'sender_number' => $from, 'receiver_number' => $to] + $attributes);
        $message->forceFill(['created_at' => $at])->saveQuietly();

        return $message;
    }

    private function history(string $contact, string $party, ?string $workOrder = null): TestResponse
    {
        $query = $workOrder === null ? '' : "&work_order={$workOrder}";

        return $this->getJson("/api/portal/v1/message-history?contact={$contact}&party={$party}{$query}", ['Authorization' => 'Bearer portal-token']);
    }
}
