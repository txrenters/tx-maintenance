<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_includes_property_work_orders_and_email_history_without_management_work_orders(): void
    {
        Role::findOrCreate('woc', 'web');
        $user = User::factory()->create()->assignRole('woc');
        $owner = Owner::factory()->create(['name' => 'Property Owner']);
        $propertyWorkOrder = WorkOrder::factory()->create(['work_order_no' => 1001]);
        WorkOrder::factory()->create([
            'work_order_no' => 1002,
            'property_manager_id' => $owner->id,
        ]);
        $propertyWorkOrder->owners()->attach($owner);
        OwnerEmailNotification::factory()->create([
            'owner_id' => $owner->id,
            'work_order_id' => $propertyWorkOrder->id,
            'subject' => 'Owner update',
        ]);

        $this->actingAs($user)
            ->get(route('owners.show', $owner))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Owner/Show')
                ->where('owner.id', $owner->id)
                ->where('propertyWorkOrders.0.id', $propertyWorkOrder->id)
                ->missing('managementWorkOrders')
                ->where('emailHistory.0.subject', 'Owner update'));
    }

    public function test_non_staff_user_cannot_view_owner_show_page(): void
    {
        Role::findOrCreate('owner', 'web');
        $user = User::factory()->create()->assignRole('owner');

        $this->actingAs($user)
            ->get(route('owners.show', Owner::factory()->create()))
            ->assertForbidden();
    }
}
