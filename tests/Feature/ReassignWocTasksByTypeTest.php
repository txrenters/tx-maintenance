<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReassignWocTasksByTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->create(['name' => 'woc', 'guard_name' => 'web']);
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function wocTask(WorkOrder $workOrder, int $userId, string $status = 'pending'): WorkOrderTask
    {
        return WorkOrderTask::query()->create([
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $userId,
            'description' => 'Call tenant',
            'due_date' => now(),
            'status' => $status,
        ]);
    }

    public function test_it_reassigns_woc_tasks_on_open_work_orders_by_type(): void
    {
        $generalWoc = User::factory()->create(['email' => 'woc@texasrenter.com']);
        $generalWoc->assignRole('woc');
        $lawn = User::factory()->create(['email' => 'xservice@txhomemp.com']);
        $lawn->assignRole('woc');
        $mc = User::factory()->create(['email' => 'mc@texasrenters.com']);
        $mc->assignRole('admin');

        $openLawn = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services']);
        $openTurnover = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Turnover']);
        $closedLawn = WorkOrder::factory()->create(['status' => 'Closed', 'type' => 'Biweekly Lawn Services']);
        $openOther = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Service Request']);

        $lawnPending = $this->wocTask($openLawn, $generalWoc->id);
        $lawnCompleted = $this->wocTask($openLawn, $generalWoc->id, 'completed');
        $turnoverPending = $this->wocTask($openTurnover, $generalWoc->id);
        $closedLawnTask = $this->wocTask($closedLawn, $generalWoc->id);
        $otherTask = $this->wocTask($openOther, $generalWoc->id);

        $this->artisan('tasks:reassign-woc-by-type')->assertSuccessful();

        // Open lawn pending → lawn coordinator; Turnover → admin mc.
        $this->assertSame($lawn->id, $lawnPending->fresh()->assigned_user_id);
        $this->assertSame($mc->id, $turnoverPending->fresh()->assigned_user_id);

        // Completed (default scope), closed work order, and non-routed type untouched.
        $this->assertSame($generalWoc->id, $lawnCompleted->fresh()->assigned_user_id);
        $this->assertSame($generalWoc->id, $closedLawnTask->fresh()->assigned_user_id);
        $this->assertSame($generalWoc->id, $otherTask->fresh()->assigned_user_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $generalWoc = User::factory()->create(['email' => 'woc@texasrenter.com']);
        $generalWoc->assignRole('woc');
        $lawn = User::factory()->create(['email' => 'xservice@txhomemp.com']);
        $lawn->assignRole('woc');

        $openLawn = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services']);
        $task = $this->wocTask($openLawn, $generalWoc->id);

        $this->artisan('tasks:reassign-woc-by-type --dry-run')->assertSuccessful();

        $this->assertSame($generalWoc->id, $task->fresh()->assigned_user_id);
    }

    public function test_include_completed_flag_also_moves_completed_tasks(): void
    {
        $generalWoc = User::factory()->create(['email' => 'woc@texasrenter.com']);
        $generalWoc->assignRole('woc');
        $lawn = User::factory()->create(['email' => 'xservice@txhomemp.com']);
        $lawn->assignRole('woc');

        $openLawn = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services']);
        $completed = $this->wocTask($openLawn, $generalWoc->id, 'completed');

        $this->artisan('tasks:reassign-woc-by-type --include-completed')->assertSuccessful();

        $this->assertSame($lawn->id, $completed->fresh()->assigned_user_id);
    }

    public function test_reassignment_is_idempotent(): void
    {
        $generalWoc = User::factory()->create(['email' => 'woc@texasrenter.com']);
        $generalWoc->assignRole('woc');
        $lawn = User::factory()->create(['email' => 'xservice@txhomemp.com']);
        $lawn->assignRole('woc');

        $openLawn = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services']);
        $task = $this->wocTask($openLawn, $generalWoc->id);

        $this->artisan('tasks:reassign-woc-by-type')->assertSuccessful();
        $this->artisan('tasks:reassign-woc-by-type')->assertSuccessful();

        $this->assertSame($lawn->id, $task->fresh()->assigned_user_id);
    }
}
