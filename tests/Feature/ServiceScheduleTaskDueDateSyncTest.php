<?php

namespace Tests\Feature;

use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setting or moving a service schedule must re-anchor the open template tasks'
 * due dates: vendors usually schedule after the status tasks were generated,
 * and those tasks would otherwise stay due on the generation day (WO#43094 —
 * tasks due 7/29 while the vendor scheduled 7/30–7/31).
 */
class ServiceScheduleTaskDueDateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-Acme',
            'name' => 'Acme Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->first() ?? ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 43094,
            'location' => 'PORT | 123MAIN',
        ]);
    }

    /**
     * A work_order_tasks row linked to a template task carrying the given
     * due-date rule string ("same day", "7 days from start date", ...).
     */
    private function makeTemplateTask(WorkOrder $workOrder, string $templateRule, string $dueDate, string $status = 'pending'): WorkOrderTask
    {
        $template = TaskTemplate::query()->create([
            'name' => 'Template for '.$templateRule,
            'current_service_status_id' => $workOrder->service_status_id,
        ]);

        $task = Task::query()->create([
            'name' => 'Have you Completed the Repair',
            'due_date' => $templateRule,
            'type' => 'Vendor',
            'task_template_id' => $template->id,
        ]);

        return WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'task_id' => $task->id,
            'due_date' => $dueDate,
            'status' => $status,
        ]);
    }

    public function test_creating_a_schedule_re_dates_open_template_tasks(): void
    {
        $admin = $this->makeAdmin();
        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id);

        $openTask = $this->makeTemplateTask($workOrder, 'same day', '2026-07-29');
        $completedTask = $this->makeTemplateTask($workOrder, 'same day', '2026-07-29', 'completed');
        // A task the WOC added by hand keeps the date the WOC picked.
        $manualTask = WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'task_id' => null,
            'due_date' => '2026-07-29',
        ]);

        $this->actingAs($admin)->post(route('work_order.service_schedule.create'), [
            'title' => 'Service Schedule for 43094',
            'date' => '2026-07-30',
            'end_date' => '2026-07-31',
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
        ])->assertRedirect();

        $this->assertSame('2026-07-31', $openTask->fresh()->due_date);
        $this->assertSame('2026-07-29', $completedTask->fresh()->due_date);
        $this->assertSame('2026-07-29', $manualTask->fresh()->due_date);
    }

    public function test_updating_a_schedule_re_dates_open_template_tasks(): void
    {
        $admin = $this->makeAdmin();
        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id);

        $openTask = $this->makeTemplateTask($workOrder, 'same day', '2026-07-29');

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Service Schedule for 43094',
            'scheduled_date' => '2026-07-30',
            'scheduled_end_date' => '2026-07-31',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->actingAs($admin)->put(route('work_order.service_schedule.update', $schedule), [
            'title' => 'Service Schedule for 43094',
            'date' => '2026-08-03',
            'end_date' => '2026-08-04',
            'vendor_id' => $vendor->id,
        ])->assertRedirect();

        $this->assertSame('2026-08-04', $openTask->fresh()->due_date);
    }

    public function test_from_start_date_tasks_follow_the_schedule_start(): void
    {
        $admin = $this->makeAdmin();
        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id);

        $turnoverTask = $this->makeTemplateTask($workOrder, '7 days from start date', '2026-07-29');

        $this->actingAs($admin)->post(route('work_order.service_schedule.create'), [
            'title' => 'Service Schedule for 43094',
            'date' => '2026-07-30',
            'end_date' => '2026-07-31',
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
        ])->assertRedirect();

        $this->assertSame('2026-08-06', $turnoverTask->fresh()->due_date);
    }

    public function test_deleting_the_only_schedule_leaves_due_dates_untouched(): void
    {
        $admin = $this->makeAdmin();
        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id);

        $openTask = $this->makeTemplateTask($workOrder, 'same day', '2026-07-31');

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Service Schedule for 43094',
            'scheduled_date' => '2026-07-30',
            'scheduled_end_date' => '2026-07-31',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->actingAs($admin)->delete(route('service_schedule.destroy', $schedule))
            ->assertRedirect();

        // No schedule left to anchor to — the date stays rather than jumping.
        $this->assertSame('2026-07-31', $openTask->fresh()->due_date);
    }
}
