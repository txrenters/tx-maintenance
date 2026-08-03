<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ServiceSchedule;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\TenantAppointmentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantAppointmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate starts off; a deterministic "from" number so the send fires.
        config([
            'services.twilio.tenant_schedule_sms' => false,
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

    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeSchedule(?Tenants $tenant = null, string $status = 'scheduled'): ServiceSchedule
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43900,
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ]);

        return ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->setTime(9, 0),
            'status' => $status,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);
    }

    private function notify(ServiceSchedule $schedule): void
    {
        app(TenantAppointmentNotificationService::class)->notify($schedule);
    }

    public function test_it_texts_the_tenant_when_the_appointment_is_set(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();

        $this->notify($schedule);

        $message = Conversation::query()
            ->where('work_order_id', $schedule->work_order_id)
            ->where('conversation_type', 'tenant')
            ->firstOrFail();

        $this->assertStringContainsString('Reliable Plumbing', $message->message);
        $this->assertStringContainsString('Scheduled:', $message->message);
        $this->assertStringContainsString('(Ref: WO#43900)', $message->message);
        $this->assertSame('+15125559999', $message->receiver_number);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertNotNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_the_message_carries_the_tenant_portal_link(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();

        $this->notify($schedule);

        $token = TenantUploadToken::query()
            ->where('work_order_id', $schedule->work_order_id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->firstOrFail();

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertStringContainsString($token->token, $message->message);
    }

    public function test_it_is_silent_when_the_gate_is_off(): void
    {
        // Gate defaults to off.
        Queue::fake();

        $schedule = $this->makeSchedule();

        $this->notify($schedule);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_it_respects_the_tenant_automation_mute(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();
        $schedule->work_order->setAutomationPaused('tenant', true);

        $this->notify($schedule->fresh());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
        // A muted work order does not consume the once-per-schedule claim.
        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_a_cancelled_schedule_is_never_announced(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule(status: 'cancelled');

        $this->notify($schedule);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_it_fires_only_once_per_schedule(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();

        $this->notify($schedule);
        $this->notify($schedule->fresh());

        $this->assertSame(1, Conversation::query()->where('conversation_type', 'tenant')->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_clearing_the_stamp_lets_a_reschedule_notify_again(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();

        $this->notify($schedule);

        // What ServiceScheduleController::update does when the date changes.
        $schedule->forceFill(['tenant_notified_at' => null])->save();
        $this->notify($schedule->fresh());

        $this->assertSame(2, Conversation::query()->where('conversation_type', 'tenant')->count());
        Queue::assertPushed(SendConversationMessageJob::class, 2);
    }

    public function test_without_a_tenant_phone_it_logs_the_thread_entry_but_texts_nobody(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule($this->makeTenant(phone: null));

        $this->notify($schedule);

        // The coordinator still sees the update in the tenant thread.
        $this->assertSame(1, Conversation::query()->where('conversation_type', 'tenant')->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }
}
