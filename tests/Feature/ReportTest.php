<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
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
        $created = now()->startOfMonth()->addDay();

        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 5001,
            'created_date' => $created, 'completed_date' => $created->copy()->addDays(10), 'status' => 'Closed',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 5002,
            'created_date' => $created, 'completed_date' => $created->copy()->addDays(2), 'status' => 'Closed',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.unresolved_7_days', ['year' => $created->year, 'month' => $created->month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 2)
                ->where('breached', 1)
                ->where('percentage', 50)
                ->has('rows', 1)
                ->where('rows.0.work_order_no', 5001)
            );
    }

    public function test_open_over_30_days_lists_old_open_work_orders(): void
    {
        $admin = $this->admin();
        $status = ServiceStatus::create(['name' => 'New', 'description' => 'New']);

        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6001,
            'created_date' => now()->subDays(45), 'status' => 'Open',
        ]);
        WorkOrder::factory()->create([
            'service_status_id' => $status->id, 'work_order_no' => 6002,
            'created_date' => now()->subDays(5), 'status' => 'Open',
        ]);

        $this->actingAs($admin)->get(route('reports.open_over_30_days'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('breached', 1)
                ->where('rows.0.work_order_no', 6001)
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
