<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendOwnerAppointmentNotificationJob;
use App\Jobs\SendTenantAppointmentNotificationJob;
use App\Models\ServiceSchedule;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setting a service schedule asks staff "Text the tenant now?". Yes queues
 * the tenant's appointment text as before; No saves the schedule untexted,
 * and the card's "Send tenant text" action sends it later. A form without
 * the answer (the vendor portal) keeps the automatic text.
 */
class ServiceScheduleTenantNoticeChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.tenant_schedule_sms' => true,
            'services.twilio.maintenance_from' => '+12813787957',
        ]);
    }

    private function makeStaff(string $role = 'woc'): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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

    private function makeWorkOrder(?Tenants $tenant = null): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43900,
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSchedule(WorkOrder $workOrder, array $attributes = []): ServiceSchedule
    {
        return ServiceSchedule::query()->create(array_merge([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(3)->format('Y-m-d').' 00:00:00',
            'scheduled_end_date' => null,
            'status' => 'scheduled',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ], $attributes));
    }

    /**
     * The staff dialog's create payload.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function createPayload(WorkOrder $workOrder, array $extra = []): array
    {
        return array_merge([
            'title' => 'Service Schedule for 43900',
            'date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(4)->format('Y-m-d'),
            'vendor_id' => $this->makeVendor()->id,
            'work_order_id' => $workOrder->id,
        ], $extra);
    }

    /**
     * The staff dialog's edit payload for a schedule, on the given date.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function updatePayload(ServiceSchedule $schedule, string $date, array $extra = []): array
    {
        return array_merge([
            'title' => $schedule->title,
            'date' => $date,
            'end_date' => null,
            'vendor_id' => $schedule->vendor_id,
        ], $extra);
    }

    // ---- creating a schedule ----

    public function test_store_with_yes_queues_the_tenant_text(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($this->makeStaff())
            ->postJson(route('work_order.service_schedule.create'), $this->createPayload($workOrder, ['notify_tenant' => true]))
            ->assertRedirect();

        $this->assertDatabaseHas('service_schedules', ['work_order_id' => $workOrder->id]);
        Queue::assertPushed(SendTenantAppointmentNotificationJob::class, 1);
    }

    public function test_store_with_no_saves_the_schedule_and_queues_nothing(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($this->makeStaff())
            ->postJson(route('work_order.service_schedule.create'), $this->createPayload($workOrder, ['notify_tenant' => false]))
            ->assertRedirect();

        $schedule = ServiceSchedule::query()->where('work_order_id', $workOrder->id)->firstOrFail();
        $this->assertNull($schedule->tenant_notified_at);
        Queue::assertNotPushed(SendTenantAppointmentNotificationJob::class);
    }

    public function test_store_without_the_answer_keeps_the_automatic_text(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();

        // The vendor portal's shape: no notify_tenant, owner flag set.
        $this->actingAs($this->makeStaff('admin'))
            ->post(route('work_order.service_schedule.create'), $this->createPayload($workOrder, ['notify_owner_of_schedule' => true]))
            ->assertRedirect();

        Queue::assertPushed(SendTenantAppointmentNotificationJob::class, 1);
        Queue::assertPushed(SendOwnerAppointmentNotificationJob::class, 1);
    }

    public function test_store_with_a_null_answer_is_treated_as_not_asked(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($this->makeStaff())
            ->postJson(route('work_order.service_schedule.create'), $this->createPayload($workOrder, ['notify_tenant' => null]))
            ->assertRedirect();

        Queue::assertPushed(SendTenantAppointmentNotificationJob::class, 1);
    }

    // ---- editing a schedule ----

    public function test_update_with_a_new_date_and_yes_clears_the_stamp_and_queues(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), ['tenant_notified_at' => now()->subDay()]);

        $this->actingAs($this->makeStaff())
            ->putJson(route('work_order.service_schedule.update', $schedule), $this->updatePayload($schedule, now()->addDays(6)->format('Y-m-d'), ['notify_tenant' => true]))
            ->assertRedirect();

        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertPushed(SendTenantAppointmentNotificationJob::class, 1);
    }

    public function test_update_with_a_new_date_and_no_clears_the_stamp_and_queues_nothing(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), ['tenant_notified_at' => now()->subDay()]);

        $this->actingAs($this->makeStaff())
            ->putJson(route('work_order.service_schedule.update', $schedule), $this->updatePayload($schedule, now()->addDays(6)->format('Y-m-d'), ['notify_tenant' => false]))
            ->assertRedirect();

        // The old stamp described the old date, so the card can offer
        // "Send tenant text" for the new one.
        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendTenantAppointmentNotificationJob::class);
    }

    public function test_update_with_a_new_date_and_no_answer_keeps_the_automatic_text(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), ['tenant_notified_at' => now()->subDay()]);

        $this->actingAs($this->makeStaff())
            ->put(route('work_order.service_schedule.update', $schedule), $this->updatePayload($schedule, now()->addDays(6)->format('Y-m-d')))
            ->assertRedirect();

        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertPushed(SendTenantAppointmentNotificationJob::class, 1);
    }

    public function test_update_with_the_same_date_keeps_the_stamp_even_with_yes(): void
    {
        Queue::fake();
        // Stored as a datetime, sent back by the dialog as a plain date: the
        // same day, not a reschedule.
        $schedule = $this->makeSchedule($this->makeWorkOrder(), [
            'scheduled_date' => '2026-07-30 00:00:00',
            'tenant_notified_at' => '2026-07-20 10:00:00',
        ]);

        $this->actingAs($this->makeStaff())
            ->putJson(route('work_order.service_schedule.update', $schedule), $this->updatePayload($schedule, '2026-07-30', ['notify_tenant' => true]))
            ->assertRedirect();

        $this->assertSame('2026-07-20 10:00:00', $schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendTenantAppointmentNotificationJob::class);
    }

    public function test_update_of_the_title_alone_keeps_the_stamp_and_queues_nothing(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), [
            'scheduled_date' => '2026-07-30 00:00:00',
            'tenant_notified_at' => '2026-07-20 10:00:00',
        ]);

        $this->actingAs($this->makeStaff())
            ->put(route('work_order.service_schedule.update', $schedule), $this->updatePayload($schedule, '2026-07-30', ['title' => 'Water heater - bring the long ladder']))
            ->assertRedirect();

        $this->assertSame('Water heater - bring the long ladder', $schedule->fresh()->title);
        $this->assertSame('2026-07-20 10:00:00', $schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendTenantAppointmentNotificationJob::class);
    }

    // ---- "Send tenant text" later ----

    public function test_send_tenant_text_texts_the_tenant_and_stamps_the_schedule(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder());

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertOk()
            ->assertJson(['sent' => true]);

        $this->assertNotNull($schedule->fresh()->tenant_notified_at);
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $schedule->work_order_id,
            'conversation_type' => 'tenant',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_send_tenant_text_works_for_an_admin_too(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder());

        $this->actingAs($this->makeStaff('admin'))
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertOk()
            ->assertJson(['sent' => true]);
    }

    public function test_send_tenant_text_refuses_a_schedule_already_texted(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), ['tenant_notified_at' => now()->subHour()]);

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The tenant was already texted about this schedule.');

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_send_tenant_text_refuses_when_tenant_automation_is_paused(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();
        $workOrder->setAutomationPaused('tenant', true);
        $workOrder->save();
        $schedule = $this->makeSchedule($workOrder);

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Tenant automation is paused on this work order. Turn it back on to send.');

        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_send_tenant_text_refuses_a_cancelled_or_completed_schedule(): void
    {
        Queue::fake();

        foreach (['cancelled' => 'A cancelled schedule is not announced.', 'completed' => 'A completed schedule is not announced.'] as $status => $error) {
            $schedule = $this->makeSchedule($this->makeWorkOrder(), ['status' => $status]);

            $this->actingAs($this->makeStaff())
                ->postJson(route('service_schedule.tenant_notice.send', $schedule))
                ->assertStatus(422)
                ->assertJsonPath('error', $error);

            $this->assertNull($schedule->fresh()->tenant_notified_at);
        }

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_send_tenant_text_refuses_a_work_order_that_skips_automated_messages(): void
    {
        Queue::fake();
        $workOrder = $this->makeWorkOrder();
        $workOrder->update(['type' => 'Turnover']);
        $schedule = $this->makeSchedule($workOrder);

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'This work order is marked vacant, turnover, re-key or refresh cleaning, or has no lease on file, so tenant messages are muted.');

        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_send_tenant_text_refuses_when_the_gate_is_off(): void
    {
        config(['services.twilio.tenant_schedule_sms' => false]);
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder());

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Tenant appointment texts are switched off.');

        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_send_tenant_text_refuses_a_tenant_with_no_phone(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder($this->makeTenant(null)));

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The tenant has no phone number on file.');

        // Nothing claimed, nothing logged: the automatic path would have
        // stamped the schedule and logged a thread entry with no text.
        $this->assertNull($schedule->fresh()->tenant_notified_at);
        $this->assertDatabaseCount('work_order_conversations', 0);
    }

    public function test_send_tenant_text_refuses_a_past_appointment(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), [
            'scheduled_date' => now()->subDays(2)->format('Y-m-d').' 00:00:00',
        ]);

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertStatus(422)
            ->assertJsonPath('error', 'This appointment is already in the past.');

        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }

    public function test_send_tenant_text_treats_an_appointment_ending_today_as_current(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder(), [
            'scheduled_date' => now()->subDays(2)->format('Y-m-d').' 00:00:00',
            'scheduled_end_date' => now()->format('Y-m-d').' 00:00:00',
        ]);

        $this->actingAs($this->makeStaff())
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertOk()
            ->assertJson(['sent' => true]);
    }

    public function test_send_tenant_text_is_forbidden_for_a_vendor_login(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder());

        // A vendor-role user with no vendor record: the calendar scope lets
        // the schedule bind, and the role check is what refuses.
        $this->actingAs($this->makeStaff('vendor'))
            ->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertForbidden();

        $this->assertNull($schedule->fresh()->tenant_notified_at);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_send_tenant_text_requires_a_login(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule($this->makeWorkOrder());

        $this->postJson(route('service_schedule.tenant_notice.send', $schedule))
            ->assertUnauthorized();

        $this->assertNull($schedule->fresh()->tenant_notified_at);
    }
}
