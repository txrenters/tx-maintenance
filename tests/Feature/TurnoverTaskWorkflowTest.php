<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\TaskService;
use Carbon\Carbon;
use Database\Seeders\ServiceStatusSeeder;
use Database\Seeders\TurnoverTaskTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TurnoverTaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('vendor', 'web');

        $this->seed(ServiceStatusSeeder::class);

        $this->coordinator = User::factory()->create(['email' => 'service@txhomemp.com']);
        $this->coordinator->assignRole('woc');

        $this->accounting = User::factory()->create(['email' => 'oa@texasrenters.com']);
        $this->accounting->assignRole('woc');

        $this->seed(TurnoverTaskTemplateSeeder::class);
    }

    private function statusId(string $name): int
    {
        return ServiceStatus::query()->where('name', $name)->value('id');
    }

    private function makeGenericTemplateFor(string $statusName, string $taskName = 'Generic task'): TaskTemplate
    {
        $template = TaskTemplate::query()->create([
            'name' => 'Generic - '.$statusName,
            'work_order_type' => null,
            'current_service_status_id' => $this->statusId($statusName),
            'is_current_service_status_emergency' => false,
        ]);

        $template->tasks()->create([
            'name' => $taskName,
            'type' => 'Woc',
            'due_date' => 'same day',
            'is_optional' => false,
            'is_mandatory' => true,
            'is_emergency' => false,
            'next_service_status_id' => $this->statusId('Not Changed'),
        ]);

        return $template;
    }

    public function test_seeder_creates_the_turnover_template_set_and_is_idempotent(): void
    {
        $this->assertSame(6, TaskTemplate::query()->where('work_order_type', 'Turnover')->count());

        $this->seed(TurnoverTaskTemplateSeeder::class);

        $this->assertSame(6, TaskTemplate::query()->where('work_order_type', 'Turnover')->count());
        $this->assertSame(
            14,
            TaskTemplate::query()->where('work_order_type', 'Turnover')->withCount('tasks')->get()->sum('tasks_count')
        );
    }

    public function test_new_turnover_work_order_gets_only_the_two_streamlined_tasks(): void
    {
        $this->makeGenericTemplateFor('New');

        $workOrder = WorkOrder::factory()->create(['type' => 'Turnover']);

        TaskService::createTasksForWorkOrder($workOrder, false, $this->statusId('New'));

        $tasks = WorkOrderTask::query()->where('work_order_id', $workOrder->id)->get();

        $this->assertSame([
            'Update Zone for Service Request (Look at Property)',
            'Update Management Plan (Important for Charges)',
        ], $tasks->pluck('description')->all());

        // Explicit per-task assignee on the Turnover template beats the mc@ type routing.
        $this->assertSame(
            [$this->coordinator->id],
            $tasks->pluck('assigned_user_id')->unique()->values()->all()
        );
    }

    public function test_turnover_by_category_also_gets_the_streamlined_tasks(): void
    {
        $this->makeGenericTemplateFor('New');

        // PropertyWare data sometimes carries Turnover as the category rather
        // than the type — both must hit the Turnover template set.
        $workOrder = WorkOrder::factory()->create([
            'type' => 'Service Request',
            'category' => 'Turnover',
        ]);

        TaskService::createTasksForWorkOrder($workOrder, false, $this->statusId('New'));

        $this->assertSame([
            'Update Zone for Service Request (Look at Property)',
            'Update Management Plan (Important for Charges)',
        ], WorkOrderTask::query()->where('work_order_id', $workOrder->id)->pluck('description')->all());
    }

    public function test_non_turnover_work_order_still_uses_the_generic_template(): void
    {
        $this->makeGenericTemplateFor('New', 'Check for tenant easy fix');

        $workOrder = WorkOrder::factory()->create(['type' => 'Service Request']);

        TaskService::createTasksForWorkOrder($workOrder, false, $this->statusId('New'));

        $tasks = WorkOrderTask::query()->where('work_order_id', $workOrder->id)->get();

        $this->assertSame(['Check for tenant easy fix'], $tasks->pluck('description')->all());
    }

    public function test_turnover_generates_no_tasks_on_statuses_the_workflow_skips(): void
    {
        // A generic template exists for this status, but Turnover skips it and
        // must NOT fall back to the generic task list.
        $this->makeGenericTemplateFor('Service Completed - Call Tenant for Followup');

        $workOrder = WorkOrder::factory()->create(['type' => 'Turnover']);

        TaskService::createTasksForWorkOrder(
            $workOrder,
            false,
            $this->statusId('Service Completed - Call Tenant for Followup')
        );

        $this->assertSame(0, WorkOrderTask::query()->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(
            $this->statusId('Service Completed - Call Tenant for Followup'),
            $workOrder->fresh()->service_status_id
        );
    }

    public function test_emergency_turnover_falls_back_to_the_generic_emergency_template(): void
    {
        $emergencyTemplate = TaskTemplate::query()->create([
            'name' => 'Generic - New - Emergency',
            'work_order_type' => null,
            'current_service_status_id' => $this->statusId('New'),
            'is_current_service_status_emergency' => true,
        ]);

        $emergencyTemplate->tasks()->create([
            'name' => 'Speak to tenant about the emergency',
            'type' => 'Woc',
            'due_date' => 'same day',
            'is_optional' => false,
            'is_mandatory' => true,
            'is_emergency' => true,
            'next_service_status_id' => $this->statusId('Not Changed'),
        ]);

        $workOrder = WorkOrder::factory()->create(['type' => 'Turnover']);

        TaskService::createTasksForWorkOrder($workOrder, true, $this->statusId('New'));

        $tasks = WorkOrderTask::query()->where('work_order_id', $workOrder->id)->get();

        $this->assertSame(['Speak to tenant about the emergency'], $tasks->pluck('description')->all());
    }

    public function test_after_photos_vendor_task_is_due_seven_days_from_the_start_date(): void
    {
        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-100',
            'name' => 'Turnover Vendor',
            'vendor_type' => 'Make Ready',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'type' => 'Turnover',
            'start_date' => '2026-07-01',
            'scheduled_end_date' => '2026-08-15',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::createTasksForWorkOrder($workOrder, false, $this->statusId('Scheduled'));

        $task = WorkOrderTask::query()->where('work_order_id', $workOrder->id)->first();

        $this->assertNotNull($task);
        $this->assertSame($vendorUser->id, $task->assigned_user_id);
        // Anchored to start_date + 7 days, not the scheduled end date.
        $this->assertSame('2026-07-08', Carbon::parse($task->due_date)->format('Y-m-d'));
    }

    public function test_final_template_routes_payment_tasks_to_accounting_and_closes(): void
    {
        $workOrder = WorkOrder::factory()->create(['type' => 'Turnover']);

        TaskService::createTasksForWorkOrder($workOrder, false, $this->statusId('Approved - Waiting on Payment'));

        $tasks = WorkOrderTask::query()
            ->with('task')
            ->where('work_order_id', $workOrder->id)
            ->get();

        $this->assertSame([
            'Make sure that move out inspection has been completed',
            'Confirm Bill has been paid',
            'Close Work Order',
        ], $tasks->pluck('description')->all());

        $byName = $tasks->keyBy('description');

        $this->assertSame($this->coordinator->id, $byName['Make sure that move out inspection has been completed']->assigned_user_id);
        $this->assertSame($this->accounting->id, $byName['Confirm Bill has been paid']->assigned_user_id);
        $this->assertSame($this->accounting->id, $byName['Close Work Order']->assigned_user_id);

        $this->assertSame(
            $this->statusId('Closed'),
            $byName['Close Work Order']->task->next_service_status_id
        );
    }
}
