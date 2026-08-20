<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\Invoice;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoCompleteVerifiedTasksTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $notChanged;

    private ServiceStatus $scheduledStatus;

    private TaskTemplate $taskTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notChanged = ServiceStatus::create(['name' => 'Not Changed', 'description' => 'Not Changed']);
        $this->scheduledStatus = ServiceStatus::create(['name' => 'Scheduled', 'description' => 'Scheduled']);
        $this->taskTemplate = TaskTemplate::create(['name' => 'Test checklist']);
    }

    private function template(string $name, ?ServiceStatus $next = null, bool $optional = false): Task
    {
        return Task::create([
            'name' => $name,
            'is_optional' => $optional,
            'type' => 'Woc',
            'next_service_status_id' => ($next ?? $this->notChanged)->id,
            'task_template_id' => $this->taskTemplate->id,
        ]);
    }

    private function pendingTask(WorkOrder $workOrder, Task $template): WorkOrderTask
    {
        return WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'task_id' => $template->id,
        ]);
    }

    private function photo(WorkOrder $workOrder, string $type, array $overrides = []): Attachments
    {
        return Attachments::create(array_merge([
            'title' => 'Photo',
            'type' => $type,
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
        ], $overrides));
    }

    public function test_completes_data_entry_tasks_whose_condition_holds(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'category' => 'Plumbing',
            'zone' => 'North',
            'management_plan' => 'Full Service',
            'start_date' => now()->toDateString(),
        ]);
        DB::table('work_order_vendors')->insert([
            'work_order_id' => $workOrder->id,
            'vendor_id' => Vendor::create(['propertyware_id' => 'PW-1', 'user_id' => User::factory()->create()->id])->id,
        ]);

        $tasks = collect([
            'Update Category to Match the type of repair requested',
            'Update Zone for Service Request (Look at Property)',
            'Update Management Plan (Important for Charges)',
            'Assign to Appropriate Vendor',
            'Fill in scheduled date',
        ])->map(fn (string $name) => $this->pendingTask($workOrder, $this->template($name)));

        $originalServiceStatusId = $workOrder->service_status_id;

        $this->artisan('tasks:auto-complete')->assertSuccessful();

        $tasks->each(fn (WorkOrderTask $task) => $this->assertSame('completed', $task->fresh()->status));

        $workOrder->refresh();
        $this->assertSame('Open', $workOrder->status);
        $this->assertSame($originalServiceStatusId, $workOrder->service_status_id);
    }

    public function test_leaves_tasks_pending_when_the_condition_is_not_met(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'category' => null,
            'zone' => null,
            'management_plan' => null,
            'start_date' => null,
        ]);

        $tasks = collect([
            'Update Category to Match the type of repair requested',
            'Update Zone for Service Request (Look at Property)',
            'Assign to Appropriate Vendor',
            'Fill in scheduled date',
            'Upload "before pictures of problem"',
            'Upload Invoice to app for payment',
        ])->map(fn (string $name) => $this->pendingTask($workOrder, $this->template($name)));

        $this->artisan('tasks:auto-complete')->assertSuccessful();

        $tasks->each(fn (WorkOrderTask $task) => $this->assertSame('pending', $task->fresh()->status));
    }

    public function test_completes_photo_and_invoice_tasks_when_uploads_exist(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->photo($workOrder, 'before');
        $this->photo($workOrder, 'after');
        Invoice::create([
            'title' => 'Invoice',
            'filename' => 'invoice.pdf',
            'filetype' => 'application/pdf',
            'amount' => 125.50,
            'work_order_id' => $workOrder->id,
            'vendor_id' => Vendor::create(['propertyware_id' => 'PW-2', 'user_id' => User::factory()->create()->id])->id,
        ]);

        $tasks = collect([
            'Upload "before pictures of problem"',
            'After Completing Service - take "After Photos in App"',
            'Have Before and After Photos been Uploaded?',
            'Upload Invoice to app for payment',
            'Confirm the invoice has been uploaded',
        ])->map(fn (string $name) => $this->pendingTask($workOrder, $this->template($name)));

        $this->artisan('tasks:auto-complete')->assertSuccessful();

        $tasks->each(fn (WorkOrderTask $task) => $this->assertSame('completed', $task->fresh()->status));
    }

    public function test_pictures_synced_requires_every_photo_confirmed_in_propertyware(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->photo($workOrder, 'before', ['pw_file_name' => 'before.jpg']);
        $unconfirmed = $this->photo($workOrder, 'after', ['pw_file_name' => null]);

        $task = $this->pendingTask($workOrder, $this->template('Confirm that All Pictures have synced to PW'));

        $this->artisan('tasks:auto-complete')->assertSuccessful();
        $this->assertSame('pending', $task->fresh()->status);

        $unconfirmed->forceFill(['pw_file_name' => 'after.jpg'])->save();

        $this->artisan('tasks:auto-complete')->assertSuccessful();
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_never_touches_cascading_or_optional_or_manual_tasks(): void
    {
        // Condition (a schedule) is true, but the template variants are unsafe:
        // one cascades to Scheduled (the Turnover-seeder wiring), one is a
        // Yes/No question, one is a manually-created task with no template.
        $workOrder = WorkOrder::factory()->create(['start_date' => now()->toDateString()]);

        $cascading = $this->pendingTask($workOrder, $this->template('Fill in scheduled date', $this->scheduledStatus));
        $optional = $this->pendingTask($workOrder, $this->template('Fill in Scheduled Start Date', null, true));
        $manual = WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id, 'task_id' => null]);

        $originalServiceStatusId = $workOrder->service_status_id;

        $this->artisan('tasks:auto-complete')->assertSuccessful();

        $this->assertSame('pending', $cascading->fresh()->status);
        $this->assertSame('pending', $optional->fresh()->status);
        $this->assertSame('pending', $manual->fresh()->status);
        $this->assertSame($originalServiceStatusId, $workOrder->fresh()->service_status_id);
    }

    public function test_skips_closed_work_orders(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'Closed',
            'start_date' => now()->toDateString(),
        ]);
        $task = $this->pendingTask($workOrder, $this->template('Fill in scheduled date'));

        $this->artisan('tasks:auto-complete')->assertSuccessful();

        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $workOrder = WorkOrder::factory()->create(['start_date' => now()->toDateString()]);
        $task = $this->pendingTask($workOrder, $this->template('Fill in scheduled date'));

        $this->artisan('tasks:auto-complete --dry-run')->assertSuccessful();

        $this->assertSame('pending', $task->fresh()->status);
    }
}
