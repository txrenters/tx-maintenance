<?php

namespace Tests\Feature;

use App\Console\Commands\RelinkHoaViolationTenants;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RelinkHoaViolationTenantsNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function tenantlessHoaWorkOrder(): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['tenant_id' => null]);

        TenantUploadToken::create([
            'token' => Str::random(48),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);

        return $workOrder;
    }

    public function test_notify_raises_one_unread_bell_per_tenantless_work_order(): void
    {
        $workOrder = $this->tenantlessHoaWorkOrder();

        $this->artisan('hoa:relink-tenants --dry-run --notify')->assertSuccessful();

        $activities = Activity::where('event', RelinkHoaViolationTenants::NOTIFY_EVENT)->get();
        $this->assertCount(1, $activities);
        $this->assertSame($workOrder->id, $activities->first()->properties['work_order_id']);
        $this->assertFalse($activities->first()->properties['read']);
    }

    public function test_notify_does_not_repeat_the_bell_on_later_runs(): void
    {
        $this->tenantlessHoaWorkOrder();

        $this->artisan('hoa:relink-tenants --dry-run --notify')->assertSuccessful();
        $this->artisan('hoa:relink-tenants --dry-run --notify')->assertSuccessful();

        $this->assertSame(1, Activity::where('event', RelinkHoaViolationTenants::NOTIFY_EVENT)->count());
    }

    public function test_without_notify_no_bell_is_raised(): void
    {
        $this->tenantlessHoaWorkOrder();

        $this->artisan('hoa:relink-tenants --dry-run')->assertSuccessful();

        $this->assertSame(0, Activity::where('event', RelinkHoaViolationTenants::NOTIFY_EVENT)->count());
    }
}
