<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Scopes\TaskScope;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorTaskBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->create(['name' => 'vendor', 'guard_name' => 'web']);
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function makeVendorWithUser(): Vendor
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
    }

    private function makeStatus(string $name = 'Assigned - Waiting on Scheduling'): ServiceStatus
    {
        return ServiceStatus::query()->create(['name' => $name, 'description' => 'x']);
    }

    /**
     * The generic scheduling-stage template: two Vendor checklist items and one
     * WOC coordination item, mirroring the production shape.
     */
    private function makeSchedulingTemplate(ServiceStatus $status, bool $emergency = false): TaskTemplate
    {
        $template = TaskTemplate::query()->create([
            'name' => 'Waiting on Scheduling',
            'current_service_status_id' => $status->id,
            'is_current_service_status_emergency' => $emergency,
        ]);

        foreach (['Contact Tenant to Schedule Appointment', 'Fill in Scheduled Start Date'] as $name) {
            $template->tasks()->create([
                'name' => $name,
                'type' => 'Vendor',
                'due_date' => 'same day',
                'is_optional' => false,
                'is_mandatory' => true,
                'is_emergency' => $emergency,
            ]);
        }

        $template->tasks()->create([
            'name' => 'Confirm vendor reached the tenant',
            'type' => 'Woc',
            'due_date' => 'same day',
            'is_optional' => false,
            'is_mandatory' => true,
            'is_emergency' => $emergency,
        ]);

        return $template;
    }

    private function openWorkOrder(ServiceStatus $status, array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'service_status_id' => $status->id,
        ], $attributes));
    }

    /**
     * @return array<int, string> the vendor user's visible task descriptions
     */
    private function vendorTaskDescriptions(WorkOrder $workOrder, Vendor $vendor): array
    {
        return WorkOrderTask::withoutGlobalScope(TaskScope::class)
            ->where('work_order_id', $workOrder->id)
            ->where('assigned_user_id', $vendor->user_id)
            ->orderBy('id')
            ->pluck('description')
            ->all();
    }

    public function test_it_creates_the_vendor_checklist_for_the_current_status(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame(
            ['Contact Tenant to Schedule Appointment', 'Fill in Scheduled Start Date'],
            $this->vendorTaskDescriptions($workOrder, $vendor)
        );
        // The WOC coordination task is never backfilled.
        $this->assertSame(
            2,
            WorkOrderTask::withoutGlobalScope(TaskScope::class)->where('work_order_id', $workOrder->id)->count()
        );
        // Rows are linked to their template task so completion can advance the status.
        $this->assertSame(
            0,
            WorkOrderTask::withoutGlobalScope(TaskScope::class)
                ->where('work_order_id', $workOrder->id)
                ->whereNull('task_id')
                ->count()
        );
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);
        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertCount(2, $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_it_creates_nothing_when_no_template_matches_the_status(): void
    {
        $templateStatus = $this->makeStatus();
        $this->makeSchedulingTemplate($templateStatus);
        $otherStatus = $this->makeStatus('Assigned - Waiting on Owner Approval');

        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($otherStatus);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame([], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_the_emergency_flag_selects_the_emergency_template(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status, emergency: false);
        $emergencyTemplate = TaskTemplate::query()->create([
            'name' => 'Waiting on Scheduling - Emergency',
            'current_service_status_id' => $status->id,
            'is_current_service_status_emergency' => true,
        ]);
        $emergencyTemplate->tasks()->create([
            'name' => 'Emergency: fill in the visit date',
            'type' => 'Vendor',
            'due_date' => 'same day',
            'is_optional' => false,
            'is_mandatory' => true,
            'is_emergency' => true,
        ]);

        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status, ['is_emergency' => true]);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame(
            ['Emergency: fill in the visit date'],
            $this->vendorTaskDescriptions($workOrder, $vendor)
        );
    }

    public function test_it_skips_a_vendor_whose_user_lacks_the_vendor_role(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);

        $user = User::factory()->create(); // no vendor role
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'No Portal LLC',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame(
            0,
            WorkOrderTask::withoutGlobalScope(TaskScope::class)->where('work_order_id', $workOrder->id)->count()
        );
    }

    public function test_it_skips_closed_work_orders(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status, ['status' => 'Closed']);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame([], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_it_honours_the_skip_automated_tasks_flag(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status, ['skip_automated_tasks' => true]);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame([], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_it_never_touches_the_owner_vendor_placeholder(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $vendor->update(['name' => 'OWNER VENDOR']);
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame([], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_a_vendor_with_an_open_checklist_is_left_alone(): void
    {
        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        // An open task from an earlier stage — whatever it was generated for,
        // the vendor already has a live checklist.
        WorkOrderTask::query()->create([
            'description' => 'Existing open step',
            'due_date' => now(),
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame(['Existing open step'], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_a_task_deleted_by_a_coordinator_stays_deleted(): void
    {
        $status = $this->makeStatus();
        $template = $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status);
        $workOrder->vendors()->attach($vendor->id);

        // The coordinator removed one checklist item on purpose.
        $removed = $template->tasks->firstWhere('name', 'Contact Tenant to Schedule Appointment');
        WorkOrderTask::query()->create([
            'description' => $removed->name,
            'due_date' => now(),
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
            'task_id' => $removed->id,
        ])->delete();

        TaskService::backfillVendorTasks($workOrder->id, [$vendor->id]);

        $this->assertSame(['Fill in Scheduled Start Date'], $this->vendorTaskDescriptions($workOrder, $vendor));
    }

    public function test_assigning_a_vendor_through_the_endpoint_backfills_their_checklist(): void
    {
        Queue::fake();
        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('changeWorkOrderVendors')->andReturnTrue();
        });

        $status = $this->makeStatus();
        $this->makeSchedulingTemplate($status);
        $vendor = $this->makeVendorWithUser();
        $workOrder = $this->openWorkOrder($status);

        $staff = User::factory()->create();
        $staff->assignRole('admin');

        $this->actingAs($staff)
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$vendor->id]])
            ->assertSessionHas('success');

        $this->assertSame(
            ['Contact Tenant to Schedule Appointment', 'Fill in Scheduled Start Date'],
            $this->vendorTaskDescriptions($workOrder, $vendor)
        );
        Queue::assertPushed(SendVendorWorkOrderInformation::class, 1);
        Queue::assertPushed(SendOwnerVendorAssignmentEmail::class, 1);
    }
}
