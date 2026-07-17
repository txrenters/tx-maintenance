<?php

namespace Tests\Feature;

use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class TenantShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_show_includes_details_email_history_and_work_orders(): void
    {
        $user = User::factory()->create();
        $tenant = Tenants::factory()->create(['email' => 'tenant@example.com']);
        $workOrder = WorkOrder::factory()->create(['tenant_id' => $tenant->id]);
        TenantEmailNotification::factory()->create([
            'tenant_id' => $tenant->id,
            'to_email' => $tenant->email,
            'subject' => 'Scheduled service reminder',
        ]);
        Gate::shouldReceive('authorize')
            ->once()
            ->with('view_tenant', Mockery::on(fn (Tenants $boundTenant): bool => $boundTenant->is($tenant)));

        $this->actingAs($user)
            ->get(route('tenants.show', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Show')
                ->where('tenant.id', $tenant->id)
                ->where('emailHistory.0.subject', 'Scheduled service reminder')
                ->where('workOrders.0.id', $workOrder->id));
    }
}
