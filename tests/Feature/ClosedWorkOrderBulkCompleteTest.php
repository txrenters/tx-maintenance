<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClosedWorkOrderBulkCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    public function test_index_lists_all_closed_work_orders_with_unfinished_counts(): void
    {
        $user = $this->actingUser();

        $closedWithTasks = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        WorkOrderTask::factory()->count(2)->create(['work_order_id' => $closedWithTasks->id]);
        WorkOrderTask::factory()->completed()->create(['work_order_id' => $closedWithTasks->id]);

        $closedAllDone = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        WorkOrderTask::factory()->completed()->create(['work_order_id' => $closedAllDone->id]);

        $closedNoTasks = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $open = WorkOrder::factory()->create(['status' => 'Open']);
        WorkOrderTask::factory()->create(['work_order_id' => $open->id]);

        $response = $this->actingAs($user)->get(route('tasks.index'));

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($closedWithTasks, $closedAllDone, $closedNoTasks, $open) {
            $page->has('closedWorkOrders', 3);

            $byId = collect($page->toArray()['props']['closedWorkOrders'])->keyBy('id');

            $this->assertSame(2, $byId[$closedWithTasks->id]['unfinished_count']);
            $this->assertSame(0, $byId[$closedAllDone->id]['unfinished_count']);
            $this->assertSame(0, $byId[$closedNoTasks->id]['unfinished_count']);
            $this->assertArrayNotHasKey($open->id, $byId->all());
        });
    }

    public function test_incomplete_endpoint_returns_only_non_completed_tasks(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $pending = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $processing = WorkOrderTask::factory()->processing()->create(['work_order_id' => $workOrder->id]);
        WorkOrderTask::factory()->completed()->create(['work_order_id' => $workOrder->id]);

        $response = $this->actingAs($user)->getJson(route('tasks.incomplete', $workOrder));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$pending->id, $processing->id], $ids);
    }

    public function test_incomplete_endpoint_rejects_non_closed_work_order(): void
    {
        $user = $this->actingUser();
        $open = WorkOrder::factory()->create(['status' => 'Open']);

        $this->actingAs($user)
            ->getJson(route('tasks.incomplete', $open))
            ->assertStatus(422);
    }

    public function test_bulk_complete_marks_given_tasks_completed(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $taskA = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $taskB = WorkOrderTask::factory()->processing()->create(['work_order_id' => $workOrder->id]);

        $response = $this->actingAs($user)->postJson(
            route('tasks.bulk_complete', $workOrder),
            ['task_ids' => [$taskA->id, $taskB->id]]
        );

        $response->assertOk();
        $response->assertJson(['completed_count' => 2]);

        $this->assertSame('completed', $taskA->fresh()->status);
        $this->assertSame('completed', $taskB->fresh()->status);
    }

    public function test_bulk_complete_does_not_cascade_or_change_work_order(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        $originalServiceStatusId = $workOrder->service_status_id;

        WorkOrderTask::factory()->count(3)->create(['work_order_id' => $workOrder->id]);
        $taskIds = $workOrder->tasks()->pluck('id')->all();
        $taskCountBefore = $workOrder->tasks()->count();

        $this->actingAs($user)
            ->postJson(route('tasks.bulk_complete', $workOrder), ['task_ids' => $taskIds])
            ->assertOk();

        $workOrder->refresh();
        $this->assertSame('Closed', $workOrder->status);
        $this->assertSame($originalServiceStatusId, $workOrder->service_status_id);
        $this->assertSame($taskCountBefore, $workOrder->tasks()->count());
    }

    public function test_bulk_complete_ignores_tasks_from_another_work_order(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        $otherWorkOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $mine = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id]);
        $theirs = WorkOrderTask::factory()->create(['work_order_id' => $otherWorkOrder->id]);

        $response = $this->actingAs($user)->postJson(
            route('tasks.bulk_complete', $workOrder),
            ['task_ids' => [$mine->id, $theirs->id]]
        );

        $response->assertOk();
        $response->assertJson(['completed_count' => 1]);

        $this->assertSame('completed', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_bulk_complete_completes_optional_task_without_yes_no_value(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $optional = WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'option' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tasks.bulk_complete', $workOrder), ['task_ids' => [$optional->id]])
            ->assertOk();

        $optional->refresh();
        $this->assertSame('completed', $optional->status);
        $this->assertNull($optional->option);
    }

    public function test_bulk_complete_requires_task_ids(): void
    {
        $user = $this->actingUser();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $this->actingAs($user)
            ->postJson(route('tasks.bulk_complete', $workOrder), ['task_ids' => []])
            ->assertStatus(422);
    }
}
