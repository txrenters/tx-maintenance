<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Removing a vendor from a work order only detaches the pivot row — the
 * work_order_conversations rows stay — but the conversation UI's vendor picker
 * used to list current assignees only, so the removed vendor's thread became
 * unreachable. The vendor-conversation endpoint now hands staff a
 * former_vendors list so those threads stay readable.
 */
class VendorConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeStatus(): ServiceStatus
    {
        return ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function makeVendor(string $name, string $pwId, ?string $phone = null, ?string $twilioNumber = null): Vendor
    {
        $user = User::factory()->create($phone ? ['phone' => $phone] : []);

        return Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => $name,
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => $user->id,
            'twilio_number' => $twilioNumber,
        ]);
    }

    public function test_staff_receive_removed_vendors_whose_tagged_messages_remain(): void
    {
        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $status = $this->makeStatus();
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 6001,
        ]);

        $currentVendor = $this->makeVendor('Current Plumbing', 'V-601');
        $removedVendor = $this->makeVendor('Removed Roofing', 'V-602');
        $workOrder->vendors()->attach([$currentVendor->id, $removedVendor->id]);

        Conversation::query()->create([
            'message' => 'REMOVED_VENDOR_THREAD_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $removedVendor->id,
            'conversation_type' => 'vendor',
        ]);

        // Same flow the Assign Vendor dialog uses: sync() detaches the vendor
        // but never touches the conversation rows.
        $workOrder->vendors()->sync([$currentVendor->id]);

        $response = $this->actingAs($woc)
            ->getJson(route('work_order.vendor_conversation', $workOrder))
            ->assertOk();

        $this->assertSame(
            [$currentVendor->id],
            array_column($response->json('vendors'), 'id'),
            'Assigned vendors must stay limited to current assignees.'
        );
        $this->assertSame(
            [$removedVendor->id],
            array_column($response->json('former_vendors'), 'id')
        );
        $this->assertTrue($response->json('former_vendors.0.removed_from_work_order'));
        $response->assertSee('REMOVED_VENDOR_THREAD_MESSAGE');
    }

    public function test_removed_vendor_is_found_by_phone_on_untagged_messages(): void
    {
        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $status = $this->makeStatus();
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 6002,
        ]);

        // Inbound replies are stored without a vendor_id tag; attribution is
        // by phone number, so detection must work from the numbers alone.
        $removedVendor = $this->makeVendor('Legacy Electric', 'V-603', '+15125550303');

        Conversation::query()->create([
            'message' => 'UNTAGGED_REPLY_FROM_REMOVED_VENDOR',
            'work_order_id' => $workOrder->id,
            'vendor_id' => null,
            'sender_number' => '+15125550303',
            'receiver_number' => '+15125559999',
            'conversation_type' => 'vendor',
        ]);

        $response = $this->actingAs($woc)
            ->getJson(route('work_order.vendor_conversation', $workOrder))
            ->assertOk();

        $this->assertSame(
            [$removedVendor->id],
            array_column($response->json('former_vendors'), 'id')
        );
    }

    public function test_assigned_vendors_and_uninvolved_vendors_are_never_listed_as_former(): void
    {
        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $status = $this->makeStatus();
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 6003,
        ]);

        $assignedVendor = $this->makeVendor('Assigned HVAC', 'V-604');
        $workOrder->vendors()->attach([$assignedVendor->id]);

        // A vendor with no trace on this work order must not surface either.
        $this->makeVendor('Uninvolved Vendor', 'V-605');

        Conversation::query()->create([
            'message' => 'ASSIGNED_VENDOR_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $assignedVendor->id,
            'conversation_type' => 'vendor',
        ]);

        $response = $this->actingAs($woc)
            ->getJson(route('work_order.vendor_conversation', $workOrder))
            ->assertOk();

        $this->assertSame([], $response->json('former_vendors'));
    }

    public function test_vendor_role_never_receives_former_vendors(): void
    {
        Role::findOrCreate('vendor', 'web');

        $status = $this->makeStatus();
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 6004,
        ]);

        $vendor = $this->makeVendor('Assigned Vendor', 'V-606');
        $vendor->user->assignRole('vendor');
        $workOrder->vendors()->attach([$vendor->id]);

        $removedVendor = $this->makeVendor('Removed Vendor', 'V-607');
        Conversation::query()->create([
            'message' => 'REMOVED_VENDOR_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $removedVendor->id,
            'conversation_type' => 'vendor',
        ]);

        $response = $this->actingAs($vendor->user)
            ->getJson(route('work_order.vendor_conversation', $workOrder))
            ->assertOk();

        $this->assertNull($response->json('former_vendors'));
    }
}
