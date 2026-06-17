<?php

namespace Tests\Feature;

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

    public function test_vendor_only_sees_notifications_for_their_work_orders_or_phone(): void
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

        $mine = Activity::create(['log_name' => 'default', 'description' => 'mine-wo', 'properties' => ['work_order_id' => $myWo->id]]);
        $byPhone = Activity::create(['log_name' => 'default', 'description' => 'mine-phone', 'properties' => ['receiverNumber' => '+15125551234']]);
        $otherWoActivity = Activity::create(['log_name' => 'default', 'description' => 'other-wo', 'properties' => ['work_order_id' => $otherWo->id]]);
        $unrelated = Activity::create(['log_name' => 'default', 'description' => 'unrelated', 'properties' => ['message' => 'hi']]);

        $response = $this->actingAs($user)->getJson('/notifications')->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($byPhone->id, $ids);
        $this->assertNotContains($otherWoActivity->id, $ids);
        $this->assertNotContains($unrelated->id, $ids); // the old leak
    }
}
