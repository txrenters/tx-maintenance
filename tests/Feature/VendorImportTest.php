<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('vendor', 'web');
    }

    public function test_it_creates_vendor_and_linked_user_keyed_on_propertyware_id(): void
    {
        $vendor = (new VendorService)->handle([
            'id' => 778899,
            'name' => 'Acme Plumbing',
            'email' => 'acme@example.com',
            'phone' => '5125551234',
            'type' => 'Plumbing',
            'active' => true,
        ]);

        $this->assertNotNull($vendor);
        // propertyware_id is the PropertyWare ID, NOT the local user id (the old bug).
        $this->assertSame('778899', (string) $vendor->propertyware_id);

        $user = User::where('email', 'acme@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($user->id, $vendor->user_id);
        $this->assertNotSame((string) $user->id, (string) $vendor->propertyware_id);
        $this->assertTrue($user->hasRole('vendor'));
    }

    public function test_reimport_updates_instead_of_duplicating(): void
    {
        (new VendorService)->handle([
            'id' => 778899, 'name' => 'Acme Plumbing', 'email' => 'acme@example.com', 'active' => true,
        ]);
        (new VendorService)->handle([
            'id' => 778899, 'name' => 'Acme Plumbing LLC', 'email' => 'acme@example.com', 'active' => false, 'type' => 'Plumbing',
        ]);

        $this->assertSame(1, Vendor::where('propertyware_id', 778899)->count());
        $this->assertDatabaseHas('vendors', [
            'propertyware_id' => 778899,
            'name' => 'Acme Plumbing LLC',
            'is_active' => false,
        ]);
    }

    public function test_resync_fixes_a_bad_email_on_the_existing_user(): void
    {
        // First import: PropertyWare has no email -> readable placeholder.
        $vendor = (new VendorService)->handle([
            'id' => 5555, 'name' => 'Acme', 'active' => true,
        ]);
        $userId = $vendor->user_id;
        $this->assertSame('acme-5555@no-email.texasrenters.com', $vendor->fresh()->email);

        // Re-sync: PropertyWare now has the real email.
        $vendor = (new VendorService)->handle([
            'id' => 5555, 'name' => 'Acme', 'email' => 'real@acme.com', 'active' => true,
        ]);

        // Same user updated in place; no orphan, no duplicate vendor.
        $this->assertSame($userId, $vendor->user_id);
        $this->assertSame('real@acme.com', $vendor->email);

        $user = User::find($userId);
        $this->assertSame('real@acme.com', $user->email);
        // Password tracks the email (email-as-password) so login keeps working.
        $this->assertTrue(Hash::check('real@acme.com', $user->password));
        $this->assertSame(1, Vendor::where('propertyware_id', 5555)->count());
    }

    public function test_missing_email_uses_readable_placeholder_not_random_numbers(): void
    {
        $vendor = (new VendorService)->handle([
            'id' => 4321,
            'name' => 'Bob & Sons Roofing',
            'active' => true,
        ]);

        $this->assertSame('bob-sons-roofing-4321@no-email.texasrenters.com', $vendor->email);
    }
}
