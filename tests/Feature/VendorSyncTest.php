<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Vendors page "Sync from PropertyWare" button (POST vendors/{vendor}/sync):
 * re-reads one vendor from PropertyWare and updates the vendor and its user.
 */
class VendorSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The "OWNER VENDOR" record exactly as PropertyWare's REST vendor endpoint
     * returns it: every missing field is "" rather than absent or null.
     *
     * @var array<string, mixed>
     */
    private const OWNER_VENDOR_PAYLOAD = [
        'id' => 4066574337,
        'companyName' => 'OWNER VENDOR',
        'nameOnCheck' => '',
        'email' => '',
        'phone' => '',
        'otherPhone' => '',
        'fax' => '',
        'type' => 'Administrative',
        'active' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'vendor']);
        Role::create(['name' => 'admin']);
    }

    private function makeVendor(array $overrides, User $user): Vendor
    {
        return Vendor::query()->create(array_merge([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1, 100000),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => null,
            'user_id' => $user->id,
        ], $overrides));
    }

    private function sync(Vendor $vendor)
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAs($admin)->postJson(route('vendors.sync', $vendor));
    }

    public function test_a_vendor_parked_on_the_shared_blank_email_user_is_moved_to_its_own_user(): void
    {
        // Every vendor with no e-mail in PropertyWare used to share one user row
        // (email ""), so OWNER VENDOR displayed — and got texted at — whatever
        // phone had last been saved on that row: an electrician's (WO#44092).
        $shared = User::factory()->create(['email' => '', 'phone' => '3465550199']);
        $placeholder = $this->makeVendor(['propertyware_id' => 4066574337, 'name' => 'OWNER VENDOR', 'email' => ''], $shared);
        $electrician = $this->makeVendor(['email' => ''], $shared);

        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/vendors/4066574337*' => Http::response(self::OWNER_VENDOR_PAYLOAD),
        ]);

        $this->sync($placeholder)->assertOk()->assertJson(['status' => true]);

        $placeholder->refresh();
        $this->assertNotSame($shared->id, $placeholder->user_id);
        $this->assertTrue(blank($placeholder->phone), 'The placeholder must not carry a phone number.');
        $this->assertNull($placeholder->email);

        // The shared row is left exactly as it was: it is still the
        // electrician's, phone and all (the User model stores phones as E.164).
        $this->assertSame('+13465550199', $shared->fresh()->phone);
        $this->assertSame('', $shared->fresh()->email);
        $this->assertSame($shared->id, $electrician->fresh()->user_id);
    }

    public function test_a_vendor_with_its_own_user_is_updated_in_place(): void
    {
        $user = User::factory()->create(['email' => 'office@gradysair.example', 'phone' => null]);
        $vendor = $this->makeVendor(['propertyware_id' => 761069582, 'name' => "Grady's Air & Heat", 'email' => 'office@gradysair.example'], $user);

        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/vendors/761069582*' => Http::response([
                'id' => 761069582,
                'companyName' => "Grady's Air & Heat",
                'email' => 'office@gradysair.example',
                'phone' => '(281) 775-1740',
                'otherPhone' => '',
                'type' => 'HVAC',
                'active' => false,
            ]),
        ]);

        $this->sync($vendor)->assertOk();

        $vendor->refresh();
        $this->assertSame($user->id, $vendor->user_id);
        $this->assertSame('+12817751740', $vendor->phone);
        $this->assertSame('office@gradysair.example', $vendor->email);
        $this->assertFalse($vendor->is_active);
    }
}
