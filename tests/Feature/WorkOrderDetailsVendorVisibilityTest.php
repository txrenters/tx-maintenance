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

class WorkOrderDetailsVendorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_only_receives_their_own_vendor_assignment_on_work_order_details(): void
    {
        Role::query()->create(['name' => 'vendor', 'guard_name' => 'web']);

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $visibleVendorUser = User::factory()->create();
        $visibleVendorUser->assignRole('vendor');

        $visibleVendor = Vendor::query()->create([
            'propertyware_id' => 'V-301',
            'name' => 'Visible Vendor HVAC',
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => $visibleVendorUser->id,
        ]);

        $hiddenVendorUser = User::factory()->create();
        $hiddenVendor = Vendor::query()->create([
            'propertyware_id' => 'V-302',
            'name' => 'Hidden Vendor Electric',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'user_id' => $hiddenVendorUser->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
        ]);

        $workOrder->vendors()->attach([$visibleVendor->id, $hiddenVendor->id]);

        $response = $this->actingAs($visibleVendorUser)
            ->get(route('work_orders.details', $workOrder));

        $response->assertOk()
            ->assertSee('Visible Vendor HVAC')
            ->assertDontSee('Hidden Vendor Electric');
    }

    public function test_logged_in_vendor_only_sees_their_own_conversation_thread(): void
    {
        Role::query()->create(['name' => 'vendor', 'guard_name' => 'web']);

        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $ownVendorUser = User::factory()->create(['phone' => '+15125550101']);
        $ownVendorUser->assignRole('vendor');
        $ownVendor = Vendor::query()->create([
            'propertyware_id' => 'V-401',
            'name' => 'Own Vendor',
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => $ownVendorUser->id,
        ]);

        $otherVendorUser = User::factory()->create(['phone' => '+15125550202']);
        $otherVendor = Vendor::query()->create([
            'propertyware_id' => 'V-402',
            'name' => 'Other Vendor',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'user_id' => $otherVendorUser->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 7788,
        ]);
        $workOrder->vendors()->attach([$ownVendor->id, $otherVendor->id]);

        // Tagged to this vendor -> visible.
        Conversation::query()->create([
            'message' => 'OWN_VENDOR_TAGGED_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $ownVendor->id,
            'conversation_type' => 'vendor',
        ]);

        // Tagged to the other vendor -> must never appear.
        Conversation::query()->create([
            'message' => 'OTHER_VENDOR_TAGGED_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $otherVendor->id,
            'conversation_type' => 'vendor',
        ]);

        // Legacy untagged message on the other vendor's number -> must never appear.
        Conversation::query()->create([
            'message' => 'OTHER_VENDOR_LEGACY_MESSAGE',
            'work_order_id' => $workOrder->id,
            'vendor_id' => null,
            'sender_number' => '+15125550202',
            'conversation_type' => 'vendor',
        ]);

        $response = $this->actingAs($ownVendorUser)
            ->getJson(route('work_order.vendor_conversation', $workOrder));

        $response->assertOk()
            ->assertSee('OWN_VENDOR_TAGGED_MESSAGE')
            ->assertDontSee('OTHER_VENDOR_TAGGED_MESSAGE')
            ->assertDontSee('OTHER_VENDOR_LEGACY_MESSAGE');
    }

    public function test_conversation_endpoint_requires_authentication(): void
    {
        $status = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 9911,
        ]);

        Conversation::query()->create([
            'message' => 'SECRET_VENDOR_MESSAGE',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
        ]);

        // Without a resolved user the scope cannot isolate anyone, so the
        // endpoint must reject the request rather than serve every message.
        $this->getJson(route('work_order.vendor_conversation', $workOrder))
            ->assertUnauthorized();
    }
}
