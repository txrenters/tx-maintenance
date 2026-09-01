<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\ServiceSchedule;
use App\Models\Technician;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\TenantAppointmentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private function makeSchedule(?Tenants $tenant = null, string $status = 'scheduled', ?Technician $technician = null): ServiceSchedule
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
            'technician_id' => $technician?->id,
        ]);
    }

    private function makeTechnician(bool $withPhoto): Technician
    {
        $attributes = ['name' => 'Kevin Cole'];

        if ($withPhoto) {
            Storage::put('technician-photos/kevin.jpg', 'jpeg-bytes');
            $attributes += [
                'photo_path' => 'technician-photos/kevin.jpg',
                'photo_content_type' => 'image/jpeg',
            ];
        }

        return Technician::factory()->create($attributes);
    }

    /**
     * The mediaUrl the queued job would hand Twilio (a protected property).
     */
    private function jobMediaUrl(SendConversationMessageJob $job): ?string
    {
        $property = new \ReflectionProperty($job, 'mediaUrl');

        return $property->getValue($job);
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

    public function test_it_skips_a_turnover_work_order(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();
        $schedule->work_order->update(['type' => 'Turnover']);

        $this->notify($schedule->fresh());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
        // The claim is not consumed, so correcting the type re-arms it.
        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_it_skips_a_work_order_marked_vacant(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $schedule = $this->makeSchedule();
        $schedule->work_order->update(['skip_automated_tasks' => true]);

        $this->notify($schedule->fresh());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_it_skips_a_property_with_no_lease_on_file(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        // WO#43485: a new-to-market home's work order imports with no lease,
        // so its requested-by contact must not get the appointment text.
        $schedule = $this->makeSchedule();
        $schedule->work_order->update(['propertyware_id' => 43485001, 'lease_id' => null]);

        $this->notify($schedule->fresh());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
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

    public function test_a_chosen_technician_with_a_photo_turns_the_text_into_an_mms(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();
        Storage::fake();

        $schedule = $this->makeSchedule(technician: $this->makeTechnician(withPhoto: true));

        $this->notify($schedule);

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertTrue((bool) $message->is_mms);
        $this->assertStringContainsString('THMP Technician Visit Reminder', $message->message);
        $this->assertStringContainsString('Assigned Technician: Kevin Cole', $message->message);
        $this->assertStringContainsString('A photo of the technician assigned to your work order is attached', $message->message);
        $this->assertStringContainsString('Date: ', $message->message);

        $media = ConversationMedia::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame('technician-photos/kevin.jpg', $media->local_path);
        $this->assertSame('image/jpeg', $media->content_type);

        Queue::assertPushed(SendConversationMessageJob::class, function (SendConversationMessageJob $job) use ($media): bool {
            return $this->jobMediaUrl($job) === $media->public_url;
        });
    }

    public function test_a_technician_without_a_photo_adds_the_name_but_stays_a_plain_text(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();
        Storage::fake();

        $schedule = $this->makeSchedule(technician: $this->makeTechnician(withPhoto: false));

        $this->notify($schedule);

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertFalse((bool) $message->is_mms);
        $this->assertStringContainsString('THMP Technician Visit Reminder', $message->message);
        $this->assertStringContainsString('Assigned Technician: Kevin Cole', $message->message);
        $this->assertStringNotContainsString('photo of the technician', $message->message);
        $this->assertDatabaseCount('work_order_conversation_medias', 0);

        Queue::assertPushed(SendConversationMessageJob::class, function (SendConversationMessageJob $job): bool {
            return $this->jobMediaUrl($job) === null;
        });
    }

    public function test_a_carrier_unfriendly_photo_type_downgrades_to_a_plain_text(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();
        Storage::fake();

        // A row written before the JPEG/PNG-only rule tightened the uploads.
        $technician = $this->makeTechnician(withPhoto: true);
        $technician->update(['photo_content_type' => 'image/webp']);

        $this->notify($this->makeSchedule(technician: $technician));

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertFalse((bool) $message->is_mms);
        $this->assertStringNotContainsString('photo of the technician', $message->message);
        $this->assertDatabaseCount('work_order_conversation_medias', 0);
    }

    public function test_a_missing_photo_file_downgrades_to_a_plain_text(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();
        Storage::fake();

        $technician = $this->makeTechnician(withPhoto: true);
        Storage::delete('technician-photos/kevin.jpg');

        $this->notify($this->makeSchedule(technician: $technician));

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertFalse((bool) $message->is_mms);
        $this->assertStringNotContainsString('photo of the technician', $message->message);
        $this->assertDatabaseCount('work_order_conversation_medias', 0);
    }

    public function test_no_technician_leaves_the_message_as_it_always_was(): void
    {
        config(['services.twilio.tenant_schedule_sms' => true]);
        Queue::fake();

        $this->notify($this->makeSchedule());

        $message = Conversation::query()->where('conversation_type', 'tenant')->firstOrFail();

        $this->assertFalse((bool) $message->is_mms);
        $this->assertStringNotContainsString('THMP Technician Visit Reminder', $message->message);
        $this->assertStringContainsString('scheduled with Reliable Plumbing', $message->message);
        $this->assertDatabaseCount('work_order_conversation_medias', 0);
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
