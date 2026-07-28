<?php

namespace Tests\Feature;

use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_all_report_pages_render_for_admin(): void
    {
        $admin = $this->admin();

        foreach ([
            'reports.unresolved_7_days',
            'reports.not_scheduled_3_days',
            'reports.tasks_on_time',
            'reports.open_over_30_days',
        ] as $name) {
            $this->actingAs($admin)->get(route($name))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Reports/Kpi'));
        }
    }

    public function test_unresolved_7_days_counts_a_late_work_order(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);
        $ref = now()->subDays(10);

        // Still open and 10 days old -> not resolved within 7 days (breached).
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 5001,
            'created_date' => $ref, 'status' => 'Open',
        ]);
        // Closed 3 days after creation -> resolved within 7 days (compliant).
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 5002,
            'created_date' => $ref, 'completed_date' => $ref->copy()->addDays(3), 'status' => 'Closed',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.unresolved_7_days', ['year' => $ref->year, 'month' => $ref->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 2)
                ->where('breached', 1)
                ->where('percentage', 50)
                ->has('lists.0.rows', 1)
                ->where('lists.0.rows.0.work_order_no', 5001)
                ->where('lists.1.rows.0.work_order_no', 5002)
            );
    }

    public function test_open_over_30_days_lists_old_open_work_orders(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);
        $old = now()->subDays(45);

        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6001,
            'created_date' => $old, 'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6002,
            'created_date' => $old->copy(), 'status' => 'Closed',
            'completed_date' => $old->copy()->addDays(2),
        ]);

        $this->actingAs($admin)
            ->get(route('reports.open_over_30_days', ['year' => $old->year, 'month' => $old->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 1) // the closed work order is excluded
                ->where('breached', 1)
                ->where('lists.0.rows.0.work_order_no', 6001)
            );
    }

    public function test_open_over_30_days_includes_prior_months_when_filtering_current_month(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);

        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6101,
            'created_date' => now()->subDays(45), 'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6102,
            'created_date' => now()->subDays(5), 'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6103,
            'created_date' => now()->subDays(45), 'status' => 'Closed',
            'completed_date' => now()->subDays(40),
        ]);

        $this->actingAs($admin)
            ->get(route('reports.open_over_30_days', ['year' => now()->year, 'month' => now()->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 2) // the closed work order is excluded
                ->where('breached', 1)
                ->where('lists.0.rows.0.work_order_no', 6101)
                ->where('lists.1.rows.0.work_order_no', 6102)
            );
    }

    public function test_open_over_30_days_excludes_service_status_closed(): void
    {
        $admin = $this->admin();
        $open = ServiceStatus::create(['name' => 'New', 'description' => 'New']);
        $closed = ServiceStatus::create(['name' => 'Closed', 'description' => 'Closed']);

        // PW still says Open, but staff closed it locally — must not appear.
        WorkOrder::factory()->create([
            'service_status_id' => $closed->id, 'work_order_no' => 6201,
            'created_date' => now()->subDays(45), 'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $open->id, 'work_order_no' => 6202,
            'created_date' => now()->subDays(45), 'status' => 'Open',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.open_over_30_days', ['year' => now()->year, 'month' => now()->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 1)
                ->where('breached', 1)
                ->where('lists.0.rows.0.work_order_no', 6202)
            );
    }

    public function test_not_scheduled_within_3_days_splits_breached_and_compliant(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);
        $created = now()->startOfMonth()->addDay();

        $vendorUser = User::factory()->create();
        $vendor = Vendor::create([
            'propertyware_id' => 'V-9', 'name' => 'V', 'is_active' => true, 'user_id' => $vendorUser->id,
        ]);

        // No schedule -> breached
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 7001, 'created_date' => $created, 'status' => 'Open',
        ]);
        // Scheduled the same day -> compliant
        $scheduled = WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 7002, 'created_date' => $created, 'status' => 'Open',
        ]);
        ServiceSchedule::create([
            'work_order_id' => $scheduled->id, 'vendor_id' => $vendor->id,
            'title' => 'x', 'scheduled_date' => $created, 'status' => 'scheduled', 'created_at' => $created,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.not_scheduled_3_days', ['year' => $created->year, 'month' => $created->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('breached', 1)
                ->where('lists.0.rows.0.work_order_no', 7001)
                ->where('lists.1.rows.0.work_order_no', 7002)
            );
    }

    public function test_tasks_on_time_flags_late_tasks_only(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);
        $assignee = User::factory()->create();
        $due = now()->startOfMonth()->addDays(5);

        // WO 8001 has a late task -> breached.
        $wo1 = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 8001, 'created_date' => $due, 'status' => 'Open']);
        $late = WorkOrderTask::create([
            'work_order_id' => $wo1->id, 'assigned_user_id' => $assignee->id,
            'description' => 'late task', 'due_date' => $due, 'status' => 'completed',
        ]);
        WorkOrderTask::withoutGlobalScopes()->where('id', $late->id)->update(['updated_at' => $due->copy()->addDays(3)]);

        // WO 8002 has only an on-time task -> compliant.
        $wo2 = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 8002, 'created_date' => $due, 'status' => 'Open']);
        $onTime = WorkOrderTask::create([
            'work_order_id' => $wo2->id, 'assigned_user_id' => $assignee->id,
            'description' => 'on-time task', 'due_date' => $due, 'status' => 'completed',
        ]);
        WorkOrderTask::withoutGlobalScopes()->where('id', $onTime->id)->update(['updated_at' => $due->copy()->subDay()]);

        // WO 8003 is Closed with a late task -> excluded entirely.
        $wo3 = WorkOrder::factory()->create(['service_status_id' => $status->id, 'work_order_no' => 8003, 'created_date' => $due, 'status' => 'Closed']);
        $closedLate = WorkOrderTask::create([
            'work_order_id' => $wo3->id, 'assigned_user_id' => $assignee->id,
            'description' => 'closed late task', 'due_date' => $due, 'status' => 'completed',
        ]);
        WorkOrderTask::withoutGlobalScopes()->where('id', $closedLate->id)->update(['updated_at' => $due->copy()->addDays(3)]);

        $this->actingAs($admin)
            ->get(route('reports.tasks_on_time', ['year' => $due->year, 'month' => $due->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 2) // the closed work order is excluded
                ->where('breached', 1)
                ->where('lists.0.rows.0.work_order_no', 8001)
                ->where('lists.0.rows.0.late', 1)
                ->where('lists.1.rows.0.work_order_no', 8002)
            );
    }

    public function test_reports_forbidden_for_non_staff(): void
    {
        Role::findOrCreate('vendor', 'web');
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');

        $this->actingAs($vendor)->get(route('reports.unresolved_7_days'))->assertForbidden();
    }
}
