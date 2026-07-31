<?php

namespace Tests\Feature;

use App\Console\Commands\FollowUpOwnerSchedule;
use App\Jobs\SendConversationMessageJob;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OwnerScheduleFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.twilio.owner_schedule_followup_sms', true);
        config()->set('services.twilio.maintenance_number', '+15125550000');

        Queue::fake();
    }

    private function makeOwner(string $mobile = '5125551234'): Owner
    {
        return Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'email' => 'owner'.$mobile.'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A work order with an owner and a service appointment set yesterday for a
     * date still in the future — the state the follow-up chases.
     */
    private function makeScheduledWorkOrder(Owner $owner): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
        $building = Building::query()->create([
            'propertyware_id' => 7102,
            'name' => '6341 Del Monte Dr',
            'address' => '6341 Del Monte Dr',
            'portfolio_id' => 900,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43950,
            'status' => 'Open',
            'building_id' => $building->propertyware_id,
        ]);

        $workOrder->owners()->attach($owner->id);

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-1',
            'name' => 'Acme Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        ServiceSchedule::query()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'title' => 'Repair visit',
            'scheduled_date' => now()->addDays(3),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        return $workOrder;
    }

    private function makeToken(WorkOrder $workOrder, Owner $owner): OwnerPortalToken
    {
        return OwnerPortalToken::create([
            'token' => 'owner-token-'.$workOrder->id.'-'.$owner->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_it_texts_the_owner_a_follow_up_with_their_portal_link(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $message = Conversation::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($message);
        $this->assertSame('owner', $message->conversation_type);
        $this->assertStringContainsString('following up on the scheduled service appointment', $message->message);
        $this->assertStringContainsString('/owner-portal/'.$token->token, $message->message);

        Queue::assertPushed(SendConversationMessageJob::class);

        $token->refresh();
        $this->assertSame(1, (int) $token->schedule_followup_count);
        $this->assertNotNull($token->schedule_followup_last_sent_at);
    }

    public function test_it_is_a_no_op_when_the_gate_is_off(): void
    {
        config()->set('services.twilio.owner_schedule_followup_sms', false);

        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $this->makeToken($workOrder, $owner);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_pre_existing_backlog_is_never_blasted(): void
    {
        $owner = $this->makeOwner();
        $this->makeScheduledWorkOrder($owner);

        // No token: this owner was never sent a portal link, so they were never
        // asked the question and must not be followed up.
        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, OwnerPortalToken::query()->count());
    }

    public function test_it_only_texts_once_per_day(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->artisan('owners:followup-schedule')->assertSuccessful();
        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(1, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, (int) $token->fresh()->schedule_followup_count);
    }

    public function test_it_stops_once_the_owner_has_responded(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);
        $token->markResponded();

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_an_inbound_text_from_the_owner_stops_the_follow_up(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        // The owner texted back on the owner thread.
        Conversation::create([
            'message' => 'Yes, please call me at the appointment.',
            'sender_number' => '+15125551234',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => false,
            'is_mms' => false,
        ]);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        // Nothing new was sent, and the token is now marked as responded.
        $this->assertSame(1, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_it_stops_at_the_cap(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $token->update([
            'schedule_followup_count' => FollowUpOwnerSchedule::MAX_NOTIFICATIONS,
        ]);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_it_stops_once_the_appointment_date_has_arrived(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $this->makeToken($workOrder, $owner);

        ServiceSchedule::query()->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->update(['scheduled_date' => now()->subHour()]);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_muted_work_order_is_skipped(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $this->makeToken($workOrder, $owner);

        $workOrder->setAutomationPaused('owner', true);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_closed_work_order_is_skipped(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeScheduledWorkOrder($owner);
        $this->makeToken($workOrder, $owner);

        $workOrder->update(['status' => 'Closed']);

        $this->artisan('owners:followup-schedule')->assertSuccessful();

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }
}
