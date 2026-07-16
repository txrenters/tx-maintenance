<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\Invoice;
use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetWorkOrderScopeCache();

        foreach (['admin', 'woc', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function tearDown(): void
    {
        $this->resetWorkOrderScopeCache();

        parent::tearDown();
    }

    /**
     * WorkOrderScope caches the resolved user in a static (per-request in prod);
     * reset it so one test's auth context never leaks into the next.
     */
    private function resetWorkOrderScopeCache(): void
    {
        $cached = new ReflectionProperty(WorkOrderScope::class, 'cachedUser');
        $cached->setAccessible(true);
        $cached->setValue(null, null);
    }

    private function newStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    /**
     * A tenant user linked to a tenant record, plus that tenant's work order and
     * an unrelated work order that must never be visible to them.
     *
     * @return array{0: User, 1: WorkOrder, 2: WorkOrder}
     */
    private function tenantWithOwnAndOther(): array
    {
        $user = User::factory()->create();
        $user->assignRole('tenant');

        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'dana@example.com',
            'user_id' => $user->id,
        ]);

        $mine = WorkOrder::factory()->create(['service_status_id' => $this->newStatus()->id, 'tenant_id' => $tenant->id]);
        $other = WorkOrder::factory()->create(['service_status_id' => $this->newStatus()->id, 'tenant_id' => null]);

        return [$user, $mine, $other];
    }

    public function test_a_tenant_sees_only_their_own_work_orders(): void
    {
        [$user, $mine, $other] = $this->tenantWithOwnAndOther();

        $this->actingAs($user);

        $ids = WorkOrder::query()->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_a_tenant_with_no_linked_record_sees_nothing_fail_closed(): void
    {
        // Tenant role but NO tenants row — the old bug let this see everything.
        $user = User::factory()->create();
        $user->assignRole('tenant');

        WorkOrder::factory()->create(['service_status_id' => $this->newStatus()->id]);
        WorkOrder::factory()->create(['service_status_id' => $this->newStatus()->id]);

        $this->actingAs($user);

        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_a_tenant_sees_only_their_own_tasks_invoices_and_attachments(): void
    {
        [$user, $mine, $other] = $this->tenantWithOwnAndOther();

        $staff = User::factory()->create();
        WorkOrderTask::query()->create(['work_order_id' => $mine->id, 'description' => 'mine', 'assigned_user_id' => $staff->id]);
        WorkOrderTask::query()->create(['work_order_id' => $other->id, 'description' => 'other', 'assigned_user_id' => $staff->id]);

        Invoice::query()->create(['work_order_id' => $mine->id, 'title' => 'mine', 'amount' => 10, 'filename' => 'mine.pdf', 'filetype' => 'application/pdf']);
        Invoice::query()->create(['work_order_id' => $other->id, 'title' => 'other', 'amount' => 20, 'filename' => 'other.pdf', 'filetype' => 'application/pdf']);

        Attachments::query()->create(['work_order_id' => $mine->id, 'title' => 'mine', 'filename' => 'a.jpg', 'user_id' => $staff->id]);
        Attachments::query()->create(['work_order_id' => $other->id, 'title' => 'other', 'filename' => 'b.jpg', 'user_id' => $staff->id]);

        $this->actingAs($user);

        $this->assertSame(1, WorkOrderTask::query()->count());
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, Attachments::query()->count());
    }

    public function test_a_tenant_is_blocked_from_the_buildings_page(): void
    {
        [$user] = $this->tenantWithOwnAndOther();

        $this->actingAs($user)
            ->get(route('buildings.index'))
            ->assertForbidden();
    }

    public function test_staff_still_see_everything(): void
    {
        [, $mine, $other] = $this->tenantWithOwnAndOther();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $ids = WorkOrder::query()->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($other->id));
    }
}
