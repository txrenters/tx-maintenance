<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\StaffActivityNotification;
use App\Services\AutomatedMessageLogService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $otherCoordinator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->coordinator = User::factory()->create()->assignRole('woc');
        $this->otherCoordinator = User::factory()->create()->assignRole('woc');
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function workOrderAssignedTo(?User $coordinator): WorkOrder
    {
        return WorkOrder::factory()->create(['user_id' => $coordinator?->id]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logEvent(string $event, array $properties = [], ?WorkOrder $subject = null): void
    {
        $activity = activity();

        if ($subject) {
            $activity->performedOn($subject);
        }

        $activity->event($event)
            ->withProperties($properties)
            ->log('Something happened');
    }

    public function test_an_event_goes_to_the_work_orders_assigned_coordinator_only(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->logEvent('work_order_message_received', [
            'work_order_id' => $workOrder->id,
            'message' => 'The water heater is out again.',
        ]);

        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherCoordinator, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->admin, StaffActivityNotification::class);
    }

    public function test_an_unassigned_work_order_falls_back_to_every_coordinator(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo(null);

        $this->logEvent('work_order_message_received', ['work_order_id' => $workOrder->id]);

        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
        Notification::assertSentTo($this->otherCoordinator, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->admin, StaffActivityNotification::class);
    }

    public function test_a_staff_audience_event_reaches_coordinators_and_admins(): void
    {
        Notification::fake();

        $this->logEvent('job_message_received', ['job_id' => 42, 'message' => 'On my way.']);

        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
        Notification::assertSentTo($this->otherCoordinator, StaffActivityNotification::class);
        Notification::assertSentTo($this->admin, StaffActivityNotification::class);
    }

    public function test_an_admin_audience_event_reaches_admins_only(): void
    {
        Notification::fake();

        $this->logEvent('jobber_reconnect_required', ['message' => 'Reauthorise Jobber.']);

        Notification::assertSentTo($this->admin, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_the_person_who_caused_the_event_is_not_notified_about_their_own_action(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->actingAs($this->coordinator);

        activity()
            ->causedBy($this->coordinator)
            ->event('work_order_message_received')
            ->withProperties(['work_order_id' => $workOrder->id])
            ->log('Work Order - New Message Received');

        Notification::assertNothingSent();
    }

    public function test_an_unmapped_event_notifies_nobody(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->logEvent('some_event_nobody_configured', ['work_order_id' => $workOrder->id]);

        Notification::assertNothingSent();
    }

    public function test_the_automated_message_ledger_never_notifies_anyone(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        activity(AutomatedMessageLogService::LOG_NAME)
            ->event('work_order_message_received')
            ->withProperties(['work_order_id' => $workOrder->id])
            ->log('Automated message sent');

        Notification::assertNothingSent();
    }

    public function test_the_master_switch_silences_every_event(): void
    {
        Notification::fake();

        config()->set('staff_notifications.enabled', false);

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->logEvent('work_order_emergency', ['work_order_id' => $workOrder->id]);

        Notification::assertNothingSent();
    }

    public function test_an_emergency_is_broadcast_as_urgent_and_links_to_the_work_order(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->logEvent('work_order_emergency', [
            'work_order_id' => $workOrder->id,
            'message' => 'No hot water, unit 4B.',
        ]);

        Notification::assertSentTo(
            $this->coordinator,
            StaffActivityNotification::class,
            function (StaffActivityNotification $notification) use ($workOrder) {
                $payload = $notification->toBroadcast($this->coordinator)->data;

                return $payload['priority'] === 'urgent'
                    && $payload['message'] === 'No hot water, unit 4B.'
                    && $payload['url'] === route('work_orders.show', $workOrder->id)
                    && $payload['work_order_id'] === $workOrder->id;
            }
        );
    }

    public function test_every_configured_event_carries_the_keys_the_desktop_client_reads(): void
    {
        Notification::fake();

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        foreach (array_keys(config('staff_notifications.events')) as $event) {
            $this->logEvent($event, ['work_order_id' => $workOrder->id, 'message' => 'Body']);
        }

        $sent = Notification::sent($this->coordinator, StaffActivityNotification::class);

        $this->assertNotEmpty($sent);

        foreach ($sent as $notification) {
            $payload = $notification->toBroadcast($this->coordinator)->data;

            foreach (['title', 'message', 'priority', 'icon', 'created_at'] as $key) {
                $this->assertArrayHasKey($key, $payload);
                $this->assertNotEmpty($payload[$key], "{$key} was empty.");
            }

            $this->assertContains($payload['priority'], ['low', 'normal', 'high', 'urgent']);
        }
    }

    public function test_a_work_order_id_pointing_at_nothing_still_notifies(): void
    {
        Notification::fake();

        // The realistic shape of this: TwilioWebhookController logs the id
        // straight off the inbound payload, matched or not.
        $this->logEvent('work_order_message_received', ['work_order_id' => 999999]);

        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_a_broken_event_never_breaks_the_caller_that_logged_it(): void
    {
        Notification::fake();

        // Malformed config: the notifier reads ['priority'] and blows up. It
        // must swallow that — this code path runs inside the Twilio webhook,
        // where an exception costs Twilio its delivery receipt.
        config()->set('staff_notifications.events.work_order_emergency', ['audience' => 'woc']);

        $this->logEvent('work_order_emergency', ['work_order_id' => 1]);

        // The activity row survived, which is what must never be lost.
        $this->assertDatabaseHas('activity_log', ['event' => 'work_order_emergency']);
        Notification::assertNothingSent();
    }

    public function test_a_staff_event_lands_on_the_recipients_own_private_channel(): void
    {
        $captured = null;

        Event::listen(BroadcastNotificationCreated::class, function ($event) use (&$captured) {
            $captured = $event;
        });

        $workOrder = $this->workOrderAssignedTo($this->coordinator);

        $this->logEvent('work_order_emergency', [
            'work_order_id' => $workOrder->id,
            'message' => 'No hot water, unit 4B.',
        ]);

        $this->assertNotNull($captured, 'The notification never reached the broadcast channel.');

        $channels = collect($captured->broadcastOn())
            ->map(fn (PrivateChannel $channel) => (string) $channel)
            ->all();

        $this->assertSame(['private-App.Models.User.'.$this->coordinator->id], $channels);
        $this->assertSame('urgent', $captured->broadcastWith()['priority']);
    }
}
