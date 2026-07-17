<?php

namespace Tests\Feature;

use App\Models\Tenants;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_distinct_tenant_contact_records_on_sqlite(): void
    {
        $user = User::factory()->create();
        Tenants::factory()->count(2)->create([
            'first_name' => 'Jane',
            'last_name' => 'Resident',
            'email' => 'jane@example.com',
        ]);
        Tenants::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Resident',
            'email' => 'john@example.com',
        ]);

        Gate::shouldReceive('authorize')
            ->once()
            ->with('view_tenants', Tenants::class);

        $this->actingAs($user)
            ->get(route('tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Index')
                ->has('tenants.data', 2)
                ->where('tenants.total', 2));
    }
}
