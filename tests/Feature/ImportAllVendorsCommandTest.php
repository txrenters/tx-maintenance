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
}
