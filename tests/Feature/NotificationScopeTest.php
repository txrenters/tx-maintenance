<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_only_sees_woc_vendor_conversation_notifications(): void
    {
        Role::findOrCreate('vendor', 'web');
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);

        $user = User::factory()->create(['phone' => '5125551234']);
        $user->assignRole('vendor');
        $vendor = Vendor::create([
            'propertyware_id' => 'V-1', 'name' => 'Acme', 'is_active' => true, 'user_id' => $user->id,
        ]);

        $myWo = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 1]);
        $myWo->vendors()->attach($vendor->id);
        $otherWo = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 2]);

        // WOC <-> vendor conversation on the vendor's work order — visible.
        $vendorConvo = Conversation::create([
            'message' => 'hello vendor', 'work_order_id' => $myWo->id, 'conversation_type' => 'vendor',
        ]);
        $visible = Activity::create([
            'log_name' => 'default', 'description' => 'vendor-msg',
            'subject_type' => Conversation::class, 'subject_id' => $vendorConvo->id,
            'properties' => ['work_order_id' => $myWo->id],
        ]);

        // Tenant conversation on the SAME work order — must not leak to the vendor.
        $tenantConvo = Conversation::create([
            'message' => 'tenant talk', 'work_order_id' => $myWo->id, 'conversation_type' => 'tenant',
        ]);
        $tenantActivity = Activity::create([
            'log_name' => 'default', 'description' => 'tenant-msg',
            'subject_type' => Conversation::class, 'subject_id' => $tenantConvo->id,
            'properties' => ['work_order_id' => $myWo->id],
        ]);

        // Vendor conversation on a work order NOT assigned to this vendor.
        $otherConvo = Conversation::create([
            'message' => 'other', 'work_order_id' => $otherWo->id, 'conversation_type' => 'vendor',
        ]);
        $otherActivity = Activity::create([
            'log_name' => 'default', 'description' => 'other-wo',
            'subject_type' => Conversation::class, 'subject_id' => $otherConvo->id,
            'properties' => ['work_order_id' => $otherWo->id],
        ]);

        // Non-conversation activity on the vendor's work order (e.g. invoice upload).
        $unrelated = Activity::create([
            'log_name' => 'default', 'description' => 'unrelated',
            'properties' => ['work_order_id' => $myWo->id, 'message' => 'invoice uploaded'],
        ]);

        $response = $this->actingAs($user)->getJson('/notifications')->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($tenantActivity->id, $ids);
        $this->assertNotContains($otherActivity->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }
}
