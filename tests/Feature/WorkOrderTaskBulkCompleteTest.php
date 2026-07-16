<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderTaskBulkCompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_complete_marks_selected_routine_tasks_completed(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        // Factory tasks have no template task, so they are routine (status flip only).
        $taskA = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $taskB = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $untouched = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);

        $response = $this->actingAs($user)->postJson(
            route('api.work_order.tasks.bulk_complete', $workOrder),
            ['tasks' => [['id' => $taskA->id], ['id' => $taskB->id]]]
        );

        $response->assertOk();
        $response->assertJson(['completed' => 2, 'skipped' => 0]);

        $this->assertSame('completed', $taskA->fresh()->status);
        $this->assertSame('completed', $taskB->fresh()->status);
        $this->assertSame('pending', $untouched->fresh()->status);
    }

    public function test_bulk_complete_does_not_change_the_work_order_for_routine_tasks(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $originalServiceStatusId = $workOrder->service_status_id;

        $tasks = WorkOrderTask::factory()->count(3)->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)->postJson(
            route('api.work_order.tasks.bulk_complete', $workOrder),
            ['tasks' => $tasks->map(fn ($task) => ['id' => $task->id])->all()]
        )->assertOk();

        $workOrder->refresh();
        $this->assertSame('Open', $workOrder->status);
        $this->assertSame($originalServiceStatusId, $workOrder->service_status_id);
    }

    public function test_bulk_complete_is_scoped_to_the_work_order(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $otherWorkOrder = WorkOrder::factory()->create(['status' => 'Open']);

        $mine = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $theirs = WorkOrderTask::factory()->create(['work_order_id' => $otherWorkOrder->id]);

        $response = $this->actingAs($user)->postJson(
            route('api.work_order.tasks.bulk_complete', $workOrder),
            ['tasks' => [['id' => $mine->id], ['id' => $theirs->id]]]
        );

        $response->assertOk();
        // The other work order's task is not found within this scope → skipped.
        $response->assertJson(['completed' => 1, 'skipped' => 1]);

        $this->assertSame('completed', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_bulk_complete_skips_already_completed_tasks(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        $pending = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $done = WorkOrderTask::factory()->completed()->create(['work_order_id' => $workOrder->id]);

        $response = $this->actingAs($user)->postJson(
            route('api.work_order.tasks.bulk_complete', $workOrder),
            ['tasks' => [['id' => $pending->id], ['id' => $done->id]]]
        );

        $response->assertOk();
        $response->assertJson(['completed' => 1, 'skipped' => 1]);
    }

    public function test_bulk_complete_requires_at_least_one_task(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        $this->actingAs($user)->postJson(
            route('api.work_order.tasks.bulk_complete', $workOrder),
            ['tasks' => []]
        )->assertStatus(422);
    }
}
