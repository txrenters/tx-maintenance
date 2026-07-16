<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ServiceScheduleController;
use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendOwnerAppointmentNotificationJob;
use App\Models\Owner;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\OwnerAppointmentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OwnerAppointmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $name = 'Acme Plumbing'): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.$name,
            'name' => $name,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeOwner(?string $mobile = '5125551234', int $ownership = 100): Owner
    {
        return Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'email' => 'owner'.$ownership.'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => $ownership,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeTenant(string $address): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'address' => $address,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(?Owner $owner = null): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->first() ?? ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
            'location' => '123 Main St',
        ]);

        // The real property owner lives on the work_order_owners pivot.
        if ($owner) {
            $workOrder->owners()->attach($owner->id);
        }

        return $workOrder;
    }

    private function makeSchedule(WorkOrder $workOrder, Vendor $vendor, string $date = '2026-07-20 14:00:00'): ServiceSchedule
    {
        return ServiceSchedule::query()->create([
            'title' => 'Service Schedule for 4567',
            'scheduled_date' => $date,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_vendor_setting_a_schedule_dispatches_the_owner_notification(): void
    {
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->post(route('vendor.portal.schedule', 'token-acme'), [
            'title' => 'Service Schedule for 4567',
            'date' => '2026-07-20',
            'end_date' => '2026-07-21',
            'description' => 'Fix exhaust fan',
        ])->assertRedirect();

        $schedule = ServiceSchedule::query()->firstOrFail();

        Queue::assertPushed(
            SendOwnerAppointmentNotificationJob::class,
            fn (SendOwnerAppointmentNotificationJob $job) => $job->serviceScheduleId === $schedule->id,
        );
    }

    public function test_a_coordinator_setting_a_schedule_does_not_notify_the_owner(): void
    {
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);

        // Mirror the WOC path: store() is called directly, with no vendor flag.
        $request = Request::create('/api/service-schedules', 'POST', [
            'title' => 'Service Schedule for 4567',
            'date' => '2026-07-20',
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
        ]);

        app(ServiceScheduleController::class)->store($request);

        Queue::assertNotPushed(SendOwnerAppointmentNotificationJob::class);
    }

    public function test_the_service_texts_the_owner_when_enabled(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner('5125551234');
        $workOrder = $this->makeWorkOrder($owner);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'receiver_number' => '+15125551234',
            'sender_number' => '+15120000000',
        ]);

        $this->assertNotNull($schedule->fresh()->owner_notified_at);

        Queue::assertPushed(SendConversationMessageJob::class);
    }

    public function test_the_service_is_silent_when_disabled(): void
    {
        // Gate defaults to off.
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $this->assertDatabaseMissing('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
        ]);
        $this->assertNull($schedule->fresh()->owner_notified_at);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_the_owner_is_notified_at_most_once_per_schedule(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner('5125551234');
        $workOrder = $this->makeWorkOrder($owner);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        $service = app(OwnerAppointmentNotificationService::class);
        $service->notify($schedule);
        $service->notify($schedule);

        $this->assertSame(1, $workOrder->owner_conversation()->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_an_owner_with_no_phone_is_logged_but_not_texted(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor();
        $owner = $this->makeOwner(null); // no mobile
        $workOrder = $this->makeWorkOrder($owner);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        // The update is still logged in the owner thread for the coordinator...
        $this->assertSame(1, $workOrder->owner_conversation()->count());
        $this->assertNotNull($schedule->fresh()->owner_notified_at);
        // ...but there is no number to text.
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_the_message_names_the_vendor_and_asks_about_the_call_or_approval(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor('Acme Plumbing');
        $owner = $this->makeOwner('5125551234');
        $workOrder = $this->makeWorkOrder($owner);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $message = $workOrder->owner_conversation()->firstOrFail()->message;

        $this->assertStringContainsString('Acme Plumbing', $message);
        $this->assertStringContainsString('Monday, July 20, 2026 at 2:00 PM', $message);
        $this->assertStringContainsString('available at the appointment time', $message);
        $this->assertStringContainsString('approve the work order', $message);
    }

    public function test_it_texts_the_primary_owner_not_the_management_company(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor();
        $managementCompany = $this->makeOwner('2810000000', 0);
        $realOwner = $this->makeOwner('5125551234', 100);
        $workOrder = $this->makeWorkOrder();
        $workOrder->owners()->attach([$managementCompany->id, $realOwner->id]);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $this->assertSame('+15125551234', $workOrder->owner_conversation()->first()->receiver_number);
    }

    public function test_the_message_uses_the_tenant_address_and_carries_the_ref_tag(): void
    {
        config(['services.twilio.owner_schedule_sms' => true]);
        config(['services.twilio.maintenance_from' => '+15120000000']);
        Queue::fake();

        $vendor = $this->makeVendor('Acme Plumbing');
        $owner = $this->makeOwner('5125551234');
        $tenant = $this->makeTenant('3326 Jane Way');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->update(['tenant_id' => $tenant->id]);
        $schedule = $this->makeSchedule($workOrder, $vendor);

        app(OwnerAppointmentNotificationService::class)->notify($schedule);

        $message = $workOrder->owner_conversation()->firstOrFail()->message;

        $this->assertStringContainsString('for your property at 3326 Jane Way', $message);
        $this->assertStringContainsString('(Ref: WO#4567)', $message);
    }
}
