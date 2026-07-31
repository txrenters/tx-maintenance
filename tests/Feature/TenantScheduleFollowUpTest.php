<?php

namespace Tests\Feature;

use App\Console\Commands\FollowUpTenantSchedule;
use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\TenantPortalLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantScheduleFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.tenant_schedule_followup_sms' => false,
            'services.twilio.maintenance_from' => '+12813787957',
        ]);
    }

    private function makeTenant(?string $phone = '5125559999'): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => $phone,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * An open work order whose appointment was set a day ago and is still ahead,
     * with the general portal token already issued (i.e. this feature has
     * already sent the tenant a link).
     *
     * @return array{0: WorkOrder, 1: TenantUploadToken}
     */
    private function scheduledWorkOrder(
        ?Tenants $tenant = null,
        int $scheduleSetDaysAgo = 1,
        int $appointmentInDays = 3,
        string $scheduleStatus = 'scheduled',
        string $status = 'Open',
        bool $withToken = true,
    ): array {
        $workOrder = WorkOrder::factory()->create([
            'status' => $status,
            'work_order_no' => 43900,
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ]);

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays($appointmentInDays)->setTime(9, 0),
            'status' => $scheduleStatus,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);

        DB::table('service_schedules')
            ->where('id', $schedule->id)
            ->update(['created_at' => now()->subDays($scheduleSetDaysAgo)]);

        $token = $withToken
            ? app(TenantPortalLinkService::class)->tokenFor($workOrder)
            : null;

        return [$workOrder, $token];
    }

    private function tokenFor(WorkOrder $workOrder): ?TenantUploadToken
    {
        return TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->first();
    }

    public function test_it_texts_the_tenant_the_day_after_the_appointment_is_set(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        $message = Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->firstOrFail();

        $this->assertStringContainsString('reminder', $message->message);
        $this->assertStringContainsString('(Ref: WO#43900)', $message->message);
        // The reminder carries the portal link.
        $this->assertStringContainsString($token->token, $message->message);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertSame(1, (int) $token->fresh()->schedule_followup_count);
    }

    public function test_it_does_not_text_on_the_same_day_the_schedule_was_set(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        $this->scheduledWorkOrder(scheduleSetDaysAgo: 0);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_is_silent_when_the_gate_is_off(): void
    {
        Queue::fake();

        $this->scheduledWorkOrder();

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_only_texts_once_per_day(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);
        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertSame(1, (int) $token->fresh()->schedule_followup_count);
    }

    public function test_it_texts_again_the_next_day(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        // Yesterday's send should not block today's.
        $token->update(['schedule_followup_last_sent_at' => now()->subDay()]);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertPushed(SendConversationMessageJob::class, 2);
        $this->assertSame(2, (int) $token->fresh()->schedule_followup_count);
    }

    public function test_it_stops_at_the_cap(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();
        $token->update([
            'schedule_followup_count' => FollowUpTenantSchedule::MAX_NOTIFICATIONS,
            'schedule_followup_last_sent_at' => now()->subDay(),
        ]);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertSame(
            FollowUpTenantSchedule::MAX_NOTIFICATIONS,
            (int) $token->fresh()->schedule_followup_count
        );
    }

    public function test_it_stops_once_the_appointment_has_arrived(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        // Appointment was yesterday: the reminder is moot.
        $this->scheduledWorkOrder(scheduleSetDaysAgo: 5, appointmentInDays: -1);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_stops_when_the_schedule_is_cancelled(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        $this->scheduledWorkOrder(scheduleStatus: 'cancelled');

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_skips_a_closed_work_order(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        $this->scheduledWorkOrder(status: 'Closed');

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_stops_when_the_tenant_engages_in_the_portal(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();
        $token->markResponded();

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertSame(0, (int) $token->fresh()->schedule_followup_count);
    }

    public function test_it_stops_when_the_tenant_replies_by_text(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();

        // An inbound message from the tenant's own number.
        Conversation::create([
            'message' => 'That time works, thanks.',
            'sender_number' => '+15125559999',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
            'is_mms' => false,
        ]);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_it_respects_the_tenant_automation_mute(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder();
        $workOrder->setAutomationPaused('tenant', true);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
        // A muted day is not consumed, so reminders resume if un-muted.
        $this->assertSame(0, (int) $token->fresh()->schedule_followup_count);
        $this->assertNull($token->fresh()->schedule_followup_last_sent_at);
    }

    public function test_the_pre_existing_backlog_is_never_texted(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        // A work order that never got a portal link has no general token, which
        // is what the fresh-start guard relies on.
        [$workOrder, $token] = $this->scheduledWorkOrder(withToken: false);

        $this->assertNull($this->tokenFor($workOrder));

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_an_excluded_token_is_never_texted(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        // What the fresh-start backfill migration stamps on existing tokens.
        [$workOrder, $token] = $this->scheduledWorkOrder();
        $token->update(['schedule_followup_excluded_at' => now()]);

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_without_a_tenant_phone_it_logs_the_thread_entry_but_texts_nobody(): void
    {
        config(['services.twilio.tenant_schedule_followup_sms' => true]);
        Queue::fake();

        [$workOrder, $token] = $this->scheduledWorkOrder($this->makeTenant(phone: null));

        $this->artisan('tenants:followup-schedule')->assertExitCode(0);

        $this->assertSame(1, Conversation::query()->where('conversation_type', 'tenant')->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }
}
