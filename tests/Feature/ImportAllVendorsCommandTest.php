<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportAllVendorsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'vendor']);
    }

    public function test_existing_users_keep_their_password(): void
    {
        $user = User::factory()->create([
            'email' => 'plumber@example.com',
            'password' => Hash::make('their-own-secret'),
        ]);
        $originalHash = $user->password;

        Http::fake([
            'api.propertyware.com/*' => Http::response([
                ['id' => 9001, 'companyName' => 'Plumber Co', 'email' => 'plumber@example.com'],
            ]),
        ]);

        $this->artisan('import:all-vendors --limit=1')->assertSuccessful();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->hasRole('vendor'));
        $this->assertDatabaseHas('vendors', ['propertyware_id' => 9001, 'name' => 'Plumber Co']);
    }

    public function test_new_vendors_are_created_with_a_password_and_role(): void
    {
        Http::fake([
            'api.propertyware.com/*' => Http::response([
                ['id' => 9002, 'companyName' => 'Roofer Co', 'email' => 'roofer@example.com'],
            ]),
        ]);

        $this->artisan('import:all-vendors --limit=1')->assertSuccessful();

        $user = User::where('email', 'roofer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEmpty($user->password);
        $this->assertTrue($user->hasRole('vendor'));
        $this->assertSame($user->id, Vendor::where('propertyware_id', 9002)->first()->user_id);
    }

    public function test_aborts_instead_of_looping_when_propertyware_cannot_be_fetched(): void
    {
        // A client error exhausts fetchBatch immediately (no retries); the old
        // "skip this batch and advance the offset" behavior looped forever.
        Http::fake(['api.propertyware.com/*' => Http::response('nope', 404)]);

        $this->artisan('import:all-vendors')->assertFailed();
    }

    public function test_vendors_without_an_email_each_get_their_own_user(): void
    {
        // PropertyWare's vendor payload carries "" (not null) for every missing
        // field. Two e-mail-less vendors used to be keyed to ONE user row
        // (email ""), so the second one's phone became the first one's too.
        Http::fake([
            'api.propertyware.com/*' => Http::response([
                ['id' => 4066574337, 'companyName' => 'OWNER VENDOR', 'nameOnCheck' => '', 'email' => '', 'phone' => '', 'otherPhone' => '', 'fax' => '', 'type' => 'Administrative', 'active' => true],
                ['id' => 761069582, 'companyName' => "Grady's Air & Heat", 'nameOnCheck' => '', 'email' => '', 'phone' => '(281) 775-1740', 'otherPhone' => '', 'fax' => '', 'type' => 'HVAC', 'active' => false],
            ]),
        ]);

        $this->artisan('import:all-vendors --limit=2')->assertSuccessful();

        $placeholder = Vendor::where('propertyware_id', 4066574337)->first();
        $hvac = Vendor::where('propertyware_id', 761069582)->first();

        $this->assertNotSame($placeholder->user_id, $hvac->user_id);
        $this->assertTrue(blank($placeholder->phone), 'OWNER VENDOR must not inherit another vendor\'s phone.');
        $this->assertSame('+12817751740', $hvac->phone);
        $this->assertNull($placeholder->email);
        $this->assertDatabaseMissing('users', ['email' => '']);
        $this->assertDatabaseHas('users', ['email' => '4066574337@texasrenter.com']);
    }

    public function test_a_vendor_parked_on_the_shared_blank_email_user_is_moved_to_its_own_user(): void
    {
        // The row every e-mail-less vendor was keyed to in production. Its
        // phone belongs to whichever record was written last, an electrician's
        // in the WO#44092 case.
        $shared = User::factory()->create(['email' => '', 'phone' => '3465550199']);
        $placeholder = Vendor::query()->create(['propertyware_id' => 4066574337, 'name' => 'OWNER VENDOR', 'email' => '', 'user_id' => $shared->id]);
        $electrician = Vendor::query()->create(['propertyware_id' => 5551, 'name' => 'Southwinds Electric LLC', 'email' => '', 'user_id' => $shared->id]);

        Http::fake([
            'api.propertyware.com/*' => Http::response([
                ['id' => 4066574337, 'companyName' => 'OWNER VENDOR', 'nameOnCheck' => '', 'email' => '', 'phone' => '', 'otherPhone' => '', 'fax' => '', 'type' => 'Administrative', 'active' => true],
            ]),
        ]);

        $this->artisan('import:all-vendors --limit=1')->assertSuccessful();

        $placeholder->refresh();
        $this->assertNotSame($shared->id, $placeholder->user_id);
        $this->assertTrue(blank($placeholder->phone));

        // The shared row is left alone: still the electrician's, phone and all.
        $this->assertSame('+13465550199', $shared->fresh()->phone);
        $this->assertSame($shared->id, $electrician->fresh()->user_id);
    }
}
