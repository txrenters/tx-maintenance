<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberSchedulerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['admin', 'woc', 'vendor', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/scheduler')->assertRedirect();
    }

    public function test_vendors_and_tenants_are_forbidden(): void
    {
        foreach (['vendor', 'tenant'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)->get('/scheduler')->assertForbidden();
        }
    }

    public function test_admins_and_wocs_see_the_page(): void
    {
        foreach (['admin', 'woc'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)
                ->get('/scheduler')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Inspection/Scheduler')
                    ->where('title', 'Scheduler'));
        }
    }
}
