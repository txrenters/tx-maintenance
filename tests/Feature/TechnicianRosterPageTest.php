<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Technicians roster page: the card grid, the profile fields, and who
 * may touch them.
 */
class TechnicianRosterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['admin', 'woc', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_the_roster_renders_for_staff_with_profile_fields(): void
    {
        Technician::factory()->create([
            'name' => 'Emanuel Hall',
            'specialty' => 'HVAC and electrical',
            'phone' => '512-555-0100',
            'email' => 'emanuel@example.test',
            'address' => '1234 Oak St, Cypress, TX 77429',
            'notes' => 'Ten years in the trade.',
        ]);
        Technician::factory()->inactive()->create(['name' => 'Away Person']);

        $this->actingAs($this->staff())
            ->get(route('technicians.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Technician/Index')
                ->has('technicians', 2)
                ->where('technicians.0.name', 'Emanuel Hall')
                ->where('technicians.0.specialty', 'HVAC and electrical')
                ->where('technicians.0.phone', '512-555-0100')
                ->where('technicians.0.email', 'emanuel@example.test')
                ->where('technicians.0.address', '1234 Oak St, Cypress, TX 77429')
                ->where('technicians.0.notes', 'Ten years in the trade.')
                ->where('technicians.0.initials', 'EH')
                ->where('technicians.0.has_photo', false)
                // Inactive rows sort after active ones.
                ->where('technicians.1.name', 'Away Person')
                ->where('technicians.1.is_active', false)
                ->has('roles'),
            );
    }

    public function test_the_roster_is_staff_only(): void
    {
        $this->actingAs($this->staff('vendor'))
            ->get(route('technicians.index'))
            ->assertForbidden();
    }

    public function test_a_coordinator_creates_and_edits_a_profile(): void
    {
        $this->actingAs($this->staff());

        $this->post(route('technicians.store'), [
            'name' => 'Thomas Allen',
            'role' => 'repair',
            'is_active' => true,
            'phone' => '512-555-0111',
            'email' => 'thomas@example.test',
            'address' => '900 Pine Ave, Kyle, TX 78640',
            'specialty' => 'Plumbing',
            'notes' => null,
        ])->assertRedirect();

        $technician = Technician::query()->where('name', 'Thomas Allen')->firstOrFail();
        $this->assertSame('Plumbing', $technician->specialty);
        $this->assertSame('900 Pine Ave, Kyle, TX 78640', $technician->address);

        $this->put(route('technicians.update', $technician), [
            'name' => 'Thomas Allen',
            'role' => 'both',
            'is_active' => false,
            'phone' => null,
            'email' => null,
            'address' => '15 Willow Ct, Buda, TX 78610',
            'specialty' => 'Plumbing and make-ready',
            'notes' => 'Bio line.',
        ])->assertRedirect();

        $technician->refresh();
        $this->assertSame('15 Willow Ct, Buda, TX 78610', $technician->address);
        $this->assertSame('both', $technician->role);
        $this->assertFalse($technician->is_active);
        $this->assertNull($technician->phone);
        $this->assertSame('Plumbing and make-ready', $technician->specialty);
        $this->assertSame('Bio line.', $technician->notes);
    }

    public function test_a_profile_can_be_removed(): void
    {
        $this->actingAs($this->staff('admin'));
        $technician = Technician::factory()->create();

        $this->delete(route('technicians.destroy', $technician))->assertRedirect();

        $this->assertDatabaseMissing('technicians', ['id' => $technician->id]);
    }

    public function test_the_address_is_optional_and_clears_back_to_empty(): void
    {
        $this->actingAs($this->staff());

        $this->post(route('technicians.store'), [
            'name' => 'No Address',
            'role' => 'repair',
            'is_active' => true,
        ])->assertRedirect();

        $technician = Technician::query()->where('name', 'No Address')->firstOrFail();
        $this->assertNull($technician->address);

        $technician->update(['address' => '77 Elm St, Austin, TX 78702']);

        // The form sends a cleared box as null, and it must land as null.
        $this->put(route('technicians.update', $technician), [
            'name' => 'No Address',
            'role' => 'repair',
            'is_active' => true,
            'address' => null,
        ])->assertRedirect();

        $this->assertNull($technician->refresh()->address);
    }

    public function test_an_overlong_address_is_rejected(): void
    {
        $this->actingAs($this->staff());

        $this->post(route('technicians.store'), [
            'name' => 'Long Address',
            'role' => 'repair',
            'is_active' => true,
            'address' => str_repeat('a', 201),
        ])->assertSessionHasErrors('address');
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->staff());

        $this->post(route('technicians.store'), [
            'name' => 'Bad Role',
            'role' => 'astronaut',
            'is_active' => true,
        ])->assertSessionHasErrors('role');
    }
}
