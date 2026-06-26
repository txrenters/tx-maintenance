<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTemplateUserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->create(['name' => 'woc', 'guard_name' => 'web']);
    }

    private function makeTemplateWithWocTask(ServiceStatus $status, ?int $assignedUserId): Task
    {
        $template = TaskTemplate::query()->create([
            'name' => 'Test template',
            'current_service_status_id' => $status->id,
            'is_current_service_status_emergency' => false,
        ]);

        return Task::query()->create([
            'name' => 'Call tenant',
            'type' => 'Woc',
            'assigned_user_id' => $assignedUserId,
            'due_date' => 'same day',
            'is_optional' => false,
            'is_mandatory' => true,
            'is_emergency' => false,
            'task_template_id' => $template->id,
        ]);
    }

    public function test_woc_task_with_specific_user_assigns_that_user(): void
    {
        $status = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'x']);

        // A non-WOC user can still be assigned directly.
        $specificUser = User::factory()->create();
        // A WOC user exists too, to prove we do NOT fall back to it.
        User::factory()->create()->assignRole('woc');

        $this->makeTemplateWithWocTask($status, $specificUser->id);

        $workOrder = WorkOrder::factory()->create(['service_status_id' => $status->id]);

        TaskService::createTasksForWorkOrder($workOrder, false, $status->id);

        $this->assertDatabaseHas('work_order_tasks', [
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $specificUser->id,
        ]);
        $this->assertSame(1, WorkOrderTask::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_woc_task_without_specific_user_falls_back_to_first_woc(): void
    {
        $status = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'x']);

        $wocUser = User::factory()->create();
        $wocUser->assignRole('woc');

        $this->makeTemplateWithWocTask($status, null);

        $workOrder = WorkOrder::factory()->create(['service_status_id' => $status->id]);

        TaskService::createTasksForWorkOrder($workOrder, false, $status->id);

        $this->assertDatabaseHas('work_order_tasks', [
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $wocUser->id,
        ]);
    }
}
