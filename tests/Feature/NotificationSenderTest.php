<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A message notification in the bell names who wrote in: their role on the
 * work order (Tenant, Owner, Vendor) and their name.
 */
class NotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        Role::findOrCreate('woc', 'web');

        return tap(User::factory()->create())->assignRole('woc');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inboundNotification(WorkOrder $workOrder, array $attributes): Activity
    {
        $message = Conversation::create($attributes + [
            'work_order_id' => $workOrder->id,
            'message' => 'Any update?',
            'receiver_number' => '+15550000000',
            'is_read' => false,
        ]);

        return activity()->performedOn($message)->event('work_order_message_received')
            ->withProperties(['work_order_id' => $workOrder->id, 'message' => 'Any update?'])
            ->log('Work Order #'.$workOrder->work_order_no.' - New Message Received');
    }

    /**
     * @return array<string, mixed>
     */
    private function bellEntryFor(User $user, Activity $activity): array
    {
        return collect($this->actingAs($user)->getJson('/notifications')->assertOk()->json())
            ->firstWhere('id', $activity->id);
    }

    public function test_an_owner_message_names_the_owner_who_wrote(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $yen = Owner::factory()->create(['first_name' => 'Yen', 'last_name' => 'Nguyen', 'phone' => '+14178958528']);
        $other = Owner::factory()->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'phone' => '+15127770000']);
        $workOrder->owners()->attach([$yen->id, $other->id]);

        $activity = $this->inboundNotification($workOrder, [
            'conversation_type' => 'owner',
            'sender_number' => '+14178958528',
        ]);

        $entry = $this->bellEntryFor($this->staffUser(), $activity);

        $this->assertSame(['role' => 'Owner', 'name' => 'Yen Nguyen'], $entry['sender']);
    }

    public function test_a_tenant_message_names_the_tenant(): void
    {
        $tenant = Tenants::factory()->create(['first_name' => 'Maria', 'last_name' => 'Lopez']);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id]);

        // Written in from a number not on file: the thread's tenant still names it.
        $activity = $this->inboundNotification($workOrder, [
            'conversation_type' => 'tenant',
            'sender_number' => '+15129990000',
        ]);

        $entry = $this->bellEntryFor($this->staffUser(), $activity);

        $this->assertSame(['role' => 'Tenant', 'name' => 'Maria Lopez'], $entry['sender']);
    }

    public function test_a_vendor_login_is_not_shown_who_wrote(): void
    {
        Role::findOrCreate('vendor', 'web');
        $user = tap(User::factory()->create())->assignRole('vendor');
        $vendor = Vendor::create(['propertyware_id' => 'V-1', 'name' => 'Acme', 'is_active' => true, 'user_id' => $user->id]);
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);

        $activity = $this->inboundNotification($workOrder, ['conversation_type' => 'vendor', 'sender_number' => '+15550000000']);

        $entry = $this->bellEntryFor($user, $activity);

        $this->assertNull($entry['sender']);
    }

    public function test_a_notification_that_is_not_a_message_has_no_sender(): void
    {
        $activity = activity()->event('work_order_description_updated')->withProperties(['message' => 'Description changed'])->log('Description updated');

        $entry = $this->bellEntryFor($this->staffUser(), $activity);

        $this->assertNull($entry['sender']);
    }
}
