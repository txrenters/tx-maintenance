<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeatureUpdatesPageTest extends TestCase
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
        $this->get('/whats-new')->assertRedirect();
    }

    public function test_vendors_and_tenants_are_forbidden(): void
    {
        foreach (['vendor', 'tenant'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)->get('/whats-new')->assertForbidden();
        }
    }

    public function test_admins_and_wocs_see_the_page(): void
    {
        foreach (['admin', 'woc'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)
                ->get('/whats-new')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('FeatureUpdates/Index')
                    ->has('updates')
                    ->has('areas'));
        }
    }

    public function test_updates_are_well_formed_and_newest_first(): void
    {
        $user = User::factory()->create()->assignRole('admin');

        $props = null;
        $this->actingAs($user)
            ->get('/whats-new')
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];

                return $page->component('FeatureUpdates/Index');
            });

        $updates = $props['updates'];
        $areas = $props['areas'];

        $this->assertNotEmpty($updates);

        $previousDate = null;
        foreach ($updates as $update) {
            $this->assertNotSame('', trim($update['title']));
            $this->assertNotSame('', trim($update['description']));
            $this->assertContains($update['area'], $areas);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $update['date']);

            if ($previousDate !== null) {
                $this->assertLessThanOrEqual($previousDate, $update['date'], 'Updates must be sorted newest first.');
            }
            $previousDate = $update['date'];
        }
    }
}
