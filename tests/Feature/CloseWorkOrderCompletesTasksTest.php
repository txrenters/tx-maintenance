<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a work order — via the Completed button or the "Close Work Order"
 * checklist task — completes whatever is left on its checklist. The rows are
 * kept (never deleted): they are the audit trail of what was done.
 *
 * The factory work orders have no vendors, so PropertyWareService::closeWorkOrder
 * bails out before making any API call.
 */
class CloseWorkOrderCompletesTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::create(['name' => 'Closed', 'description' => 'Closed']);
    }

    public function test_the_close_button_completes_remaining_tasks(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        $pending = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $processing = WorkOrderTask::factory()->processing()->create(['work_order_id' => $workOrder->id]);
        $alreadyDone = WorkOrderTask::factory()->completed()->create(['work_order_id' => $workOrder->id]);
        $otherWorkOrderTask = WorkOrderTask::factory()->create();

        $this->actingAs($user)
            ->put(route('work_orders.close', $workOrder))
            ->assertRedirect();

        $this->assertSame('Closed', $workOrder->fresh()->status);
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame('completed', $processing->fresh()->status);
        $this->assertSame('completed', $alreadyDone->fresh()->status);
        $this->assertSame('pending', $otherWorkOrderTask->fresh()->status);

        // Completed, not deleted — the checklist rows remain as the audit trail.
        $this->assertNull($pending->fresh()->deleted_at);
    }

    public function test_completing_the_close_work_order_task_completes_remaining_tasks(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        $closedStatus = ServiceStatus::where('name', 'Closed')->first();
        $closeTemplate = Task::create([
            'name' => 'Close Work Order',
            'is_optional' => false,
            'type' => 'Woc',
            'next_service_status_id' => $closedStatus->id,
            'task_template_id' => TaskTemplate::create(['name' => 'Billing checklist'])->id,
        ]);

        $closeTask = WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'task_id' => $closeTemplate->id,
        ]);
        $leftover = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)->post(
            route('api.work_order.task.change', $closeTask),
            ['status' => 'completed', 'option' => null]
        )->assertRedirect();

        $workOrder->refresh();
        $this->assertSame('Closed', $workOrder->status);
        $this->assertNotNull($workOrder->completed_date);
        $this->assertSame('completed', $closeTask->fresh()->status);
        $this->assertSame('completed', $leftover->fresh()->status);
        $this->assertNull($leftover->fresh()->deleted_at);
    }
}
