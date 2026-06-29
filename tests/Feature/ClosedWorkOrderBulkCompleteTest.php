<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClosedWorkOrderBulkCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    private function wocUser(): User
    {
        Role::findOrCreate('woc', 'web');

        return User::factory()->create()->assignRole('woc');
    }

    public function test_index_lists_only_closed_work_orders_with_unfinished_tasks(): void
    {
        $user = $this->actingUser();

        $closedWithTasks = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        WorkOrderTask::factory()->count(2)->create(['work_order_id' => $closedWithTasks->id]);
        WorkOrderTask::factory()->completed()->create(['work_order_id' => $closedWithTasks->id]);

        // Closed but everything already done — must NOT appear in the selector.
        $closedAllDone = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        WorkOrderTask::factory()->completed()->create(['work_order_id' => $closedAllDone->id]);

        // Closed with no tasks at all — must NOT appear.
        $closedNoTasks = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);

        $open = WorkOrder::factory()->create(['status' => 'Open']);
        WorkOrderTask::factory()->create(['work_order_id' => $open->id]);

        // closedWorkOrders is a deferred (Inertia::optional) prop, so it is only
        // present on a partial reload that explicitly requests it. Pass the asset
        // version so the partial request isn't rejected with a 409 version conflict.
        $version = app(HandleInertiaRequests::class)->version(request());

        $response = $this->actingAs($user)->withHeaders([
            'X-Inertia' => true,
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Task/Index',
            'X-Inertia-Partial-Data' => 'closedWorkOrders',
        ])->get(route('tasks.index'));

        $response->assertOk();

        $closedWorkOrders = $response->json('props.closedWorkOrders');
        $this->assertCount(1, $closedWorkOrders);

        $byId = collect($closedWorkOrders)->keyBy('id');
        $this->assertSame(2, $byId[$closedWithTasks->id]['unfinished_count']);
        $this->assertArrayNotHasKey($closedAllDone->id, $byId->all());
        $this->assertArrayNotHasKey($closedNoTasks->id, $byId->all());
        $this->assertArrayNotHasKey($open->id, $byId->all());
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

    public function test_bulk_complete_all_closed_completes_only_closed_work_order_tasks(): void
    {
        $woc = $this->wocUser();

        $closedA = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        $closedB = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        $open = WorkOrder::factory()->create(['status' => 'Open']);

        $closedTaskA = WorkOrderTask::factory()->count(2)->create(['work_order_id' => $closedA->id]);
        $closedTaskB = WorkOrderTask::factory()->create(['work_order_id' => $closedB->id]);
        $alreadyDone = WorkOrderTask::factory()->completed()->create(['work_order_id' => $closedA->id]);
        $openTask = WorkOrderTask::factory()->create(['work_order_id' => $open->id]);

        $response = $this->actingAs($woc)->postJson(route('tasks.bulk_complete_all_closed'));

        $response->assertOk();
        // 2 + 1 incomplete closed tasks; the already-completed one is not re-counted.
        $response->assertJson(['completed_count' => 3]);

        foreach ($closedTaskA as $task) {
            $this->assertSame('completed', $task->fresh()->status);
        }
        $this->assertSame('completed', $closedTaskB->fresh()->status);
        $this->assertSame('completed', $alreadyDone->fresh()->status);
        // Tasks on a non-closed work order must be left untouched.
        $this->assertSame('pending', $openTask->fresh()->status);
    }

    public function test_bulk_complete_all_closed_is_forbidden_for_non_privileged_users(): void
    {
        $user = $this->actingUser();
        $closed = WorkOrder::factory()->create(['status' => 'Closed', 'completed_date' => now()]);
        $task = WorkOrderTask::factory()->create(['work_order_id' => $closed->id]);

        $this->actingAs($user)
            ->postJson(route('tasks.bulk_complete_all_closed'))
            ->assertStatus(403);

        $this->assertSame('pending', $task->fresh()->status);
    }
}
