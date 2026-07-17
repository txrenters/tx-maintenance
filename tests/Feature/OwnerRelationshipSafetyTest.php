<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerRelationshipSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_index_only_lists_actual_property_owners(): void
    {
        Role::findOrCreate('woc', 'web');
        $user = User::factory()->create()->assignRole('woc');
        $propertyOwner = Owner::factory()->create(['name' => 'Actual Property Owner']);
        $managementContact = Owner::factory()->create(['name' => 'Management Contact']);
        $workOrder = WorkOrder::factory()->create(['property_manager_id' => $managementContact->id]);
        $workOrder->owners()->attach($propertyOwner);

        $this->actingAs($user)
            ->get(route('owners.index', ['type' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Owner/Index')
                ->has('owners.data', 1)
                ->where('owners.data.0.id', $propertyOwner->id));
    }

    public function test_deleting_a_property_manager_preserves_the_work_order(): void
    {
        $manager = Owner::factory()->create();
        $workOrder = WorkOrder::factory()->create(['property_manager_id' => $manager->id]);

        $manager->delete();

        $this->assertModelExists($workOrder);
        $this->assertNull($workOrder->refresh()->property_manager_id);
    }

    public function test_linked_property_owner_cannot_be_deleted_from_owner_page(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('woc', 'web');
        $user->assignRole('woc');
        $owner = Owner::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->owners()->attach($owner);

        $this->actingAs($user)
            ->delete(route('owners.destroy', $owner))
            ->assertSessionHasErrors('owner');

        $this->assertModelExists($owner);
        $this->assertModelExists($workOrder);
        $this->assertTrue($workOrder->owners()->whereKey($owner)->exists());
    }

    public function test_non_staff_user_cannot_delete_or_bulk_delete_owners(): void
    {
        Role::findOrCreate('owner', 'web');
        $user = User::factory()->create()->assignRole('owner');
        $owner = Owner::factory()->create();

        $this->actingAs($user)
            ->delete(route('owners.destroy', $owner))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('owners.bulkdelete'), ['ownersId' => [$owner->id]])
            ->assertForbidden();

        $this->assertModelExists($owner);
    }
}
