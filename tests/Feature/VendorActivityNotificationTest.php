<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\StaffActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $vendorUser;

    private Vendor $vendor;

    private User $otherVendorUser;

    private Vendor $otherVendor;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->coordinator = User::factory()->create()->assignRole('woc');

        [$this->vendorUser, $this->vendor] = $this->makeVendor('Acme Plumbing');
        [$this->otherVendorUser, $this->otherVendor] = $this->makeVendor('Rival Electric');

        $this->workOrder = WorkOrder::factory()->create(['user_id' => $this->coordinator->id]);
        $this->workOrder->vendors()->attach([
            $this->vendor->id => ['access_token' => 'token-a'],
            $this->otherVendor->id => ['access_token' => 'token-b'],
        ]);
    }

    /**
     * @return array{0: User, 1: Vendor}
     */
    private function makeVendor(string $name): array
    {
        $user = User::factory()->create()->assignRole('vendor');

        $vendor = Vendor::create([
            'propertyware_id' => fake()->unique()->numberBetween(100000, 999999),
            'name' => $name,
            'email' => $user->email,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return [$user, $vendor];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function logMessageOn(array $attributes): void
    {
        $conversation = Conversation::create(array_merge([
            'work_order_id' => $this->workOrder->id,
            'message' => 'Can you get out there tomorrow?',
            'sender_number' => '+15125550100',
            'receiver_number' => '+15125550111',
        ], $attributes));

        activity()
            ->performedOn($conversation)
            ->event('work_order_message_received')
            ->withProperties([
                'work_order_id' => $this->workOrder->id,
                'message' => $conversation->message,
            ])
            ->log('Work Order - New Message Received');
    }

    public function test_a_message_sent_to_a_vendor_notifies_that_vendors_user(): void
    {
        Notification::fake();

        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'read_by_vendor' => false,
        ]);

        Notification::assertSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_a_vendor_is_not_notified_about_a_message_they_sent_themselves(): void
    {
        Notification::fake();

        // The vendor portal stores read_by_vendor = true on the vendor's own
        // outbound message; the coordinator still needs to hear about it.
        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'read_by_vendor' => true,
        ]);

        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_a_tenant_thread_never_reaches_a_vendor(): void
    {
        Notification::fake();

        $this->logMessageOn([
            'conversation_type' => 'tenant',
            'vendor_id' => null,
            'read_by_vendor' => false,
        ]);

        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_an_owner_thread_never_reaches_a_vendor(): void
    {
        Notification::fake();

        $this->logMessageOn([
            'conversation_type' => 'owner',
            'vendor_id' => null,
            'read_by_vendor' => false,
        ]);

        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }

    public function test_a_vendor_on_the_same_work_order_never_sees_another_vendors_thread(): void
    {
        Notification::fake();

        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'read_by_vendor' => false,
        ]);

        Notification::assertSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }

    public function test_a_vendor_thread_with_no_vendor_id_notifies_no_vendor_at_all(): void
    {
        Notification::fake();

        // Cannot say whose thread it is; guessing from the work order's vendor
        // list would notify every vendor on it.
        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => null,
            'read_by_vendor' => false,
        ]);

        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }

    public function test_assignment_notifies_only_the_vendor_that_was_assigned(): void
    {
        Notification::fake();

        activity()
            ->performedOn($this->workOrder)
            ->event('vendor_auto_assigned')
            ->withProperties([
                'work_order_id' => $this->workOrder->id,
                'vendor_id' => $this->vendor->id,
                'message' => 'Vendor auto-assigned.',
            ])
            ->log('Vendor auto-assigned to Work Order');

        Notification::assertSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }

    public function test_staff_only_events_never_reach_a_vendor(): void
    {
        Notification::fake();

        foreach (['work_order_emergency', 'invoice_uploaded', 'owner_portal_approval', 'work_order_description_updated'] as $event) {
            activity()
                ->performedOn($this->workOrder)
                ->event($event)
                ->withProperties(['work_order_id' => $this->workOrder->id, 'vendor_id' => $this->vendor->id])
                ->log('Staff-internal event');
        }

        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }

    public function test_a_vendor_whose_user_does_not_use_the_app_notifies_no_vendor(): void
    {
        Notification::fake();

        // vendors.user_id is NOT NULL, so every vendor has a linked user. What
        // separates a vendor who uses the app from one who does not is the
        // vendor role — without it they never log in, and the desktop client
        // has no account to connect. They stay on email/SMS.
        $noRoleUser = User::factory()->create();

        $loginless = Vendor::create([
            'propertyware_id' => fake()->unique()->numberBetween(100000, 999999),
            'name' => 'No Login LLC',
            'user_id' => $noRoleUser->id,
            'is_active' => true,
        ]);

        activity()
            ->performedOn($this->workOrder)
            ->event('vendor_auto_assigned')
            ->withProperties(['work_order_id' => $this->workOrder->id, 'vendor_id' => $loginless->id])
            ->log('Vendor auto-assigned to Work Order');

        Notification::assertNotSentTo($noRoleUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertSentTo($this->coordinator, StaffActivityNotification::class);
    }

    public function test_the_vendors_link_points_at_their_own_board_not_the_staff_page(): void
    {
        Notification::fake();

        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'read_by_vendor' => false,
        ]);

        Notification::assertSentTo(
            $this->vendorUser,
            StaffActivityNotification::class,
            fn (StaffActivityNotification $notification) => $notification
                ->toBroadcast($this->vendorUser)->data['url'] === route('work_orders.vendor')
        );

        Notification::assertSentTo(
            $this->coordinator,
            StaffActivityNotification::class,
            fn (StaffActivityNotification $notification) => $notification
                ->toBroadcast($this->coordinator)->data['url'] === route('work_orders.show', $this->workOrder->id)
        );
    }

    public function test_who_is_logged_in_does_not_change_who_gets_notified(): void
    {
        Notification::fake();

        // ConversationScope narrows Conversation queries to the authenticated
        // user. The notifier must not inherit that, or a vendor's notification
        // would depend on who happened to trigger the event.
        $this->actingAs($this->otherVendorUser);

        $this->logMessageOn([
            'conversation_type' => 'vendor',
            'vendor_id' => $this->vendor->id,
            'read_by_vendor' => false,
        ]);

        Notification::assertSentTo($this->vendorUser, StaffActivityNotification::class);
        Notification::assertNotSentTo($this->otherVendorUser, StaffActivityNotification::class);
    }
}
