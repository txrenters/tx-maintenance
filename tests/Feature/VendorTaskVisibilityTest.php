<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorTaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $propertywareId): Vendor
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        return Vendor::query()->create([
            'propertyware_id' => $propertywareId,
            'name' => 'Test Vendor '.$propertywareId,
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    public function test_vendor_sees_a_task_assigned_to_their_own_user(): void
    {
        Role::findOrCreate('vendor', 'web');

        $vendor = $this->makeVendor('V-900');
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 9001, 'status' => 'Open']);
        $workOrder->vendors()->attach($vendor->id);

        $task = WorkOrderTask::query()->create([
            'description' => 'Trim the hedges',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        $response = $this->actingAs($vendor->user)
            ->getJson(route('api.work_order.tasks', $workOrder));

        $response->assertOk();
        $this->assertContains(
            $task->id,
            collect($response->json('tasks'))->pluck('id')->all(),
        );
    }

    public function test_vendor_does_not_see_a_task_assigned_to_a_different_user(): void
    {
        Role::findOrCreate('vendor', 'web');

        $vendor = $this->makeVendor('V-901');
        $someoneElse = User::factory()->create();

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 9002, 'status' => 'Open']);
        $workOrder->vendors()->attach($vendor->id);

        WorkOrderTask::query()->create([
            'description' => 'Internal WOC task',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $someoneElse->id,
        ]);

        $response = $this->actingAs($vendor->user)
            ->getJson(route('api.work_order.tasks', $workOrder));

        $response->assertOk();
        $this->assertEmpty($response->json('tasks'));
    }
}
