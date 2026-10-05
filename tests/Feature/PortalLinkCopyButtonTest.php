<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Portal link" button on the tenant and owner conversation tabs: a
 * coordinator copies the no-login portal link and sends it by hand.
 *
 * Built for the move to the new domain, where every link already texted or
 * emailed still points at the old host. The link handed out here is the same
 * token the automated messages carry, rebuilt on the current APP_URL.
 */
class PortalLinkCopyButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([...User::STAFF_ROLES, 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function owner(string $firstName, float $stake, ?string $mobile = '7135030427'): Owner
    {
        return Owner::query()->create([
            'first_name' => $firstName,
            'last_name' => 'Owner',
            'name' => $firstName.' Owner',
            'email' => strtolower($firstName).'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => $stake,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_staff_get_the_tenant_portal_link_on_the_current_domain(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $response = $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
            ->assertOk()
            ->assertJsonCount(1, 'links');

        $token = TenantUploadToken::query()->where('work_order_id', $workOrder->id)->sole();

        $this->assertSame(TenantUploadToken::PURPOSE_WORK_ORDER, $token->purpose);
        $response->assertJsonPath('links.0.label', 'Tenant portal link');
        $response->assertJsonPath('links.0.url', route('tenant.portal.show', $token->token));
    }

    /**
     * The link a tenant already holds must keep working: asking again hands
     * out the same token, never a second one.
     */
    public function test_asking_twice_returns_the_same_tenant_link(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $staff = $this->staff();

        $first = $this->actingAs($staff)
            ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
            ->json('links.0.url');

        $second = $this->actingAs($staff)
            ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
            ->json('links.0.url');

        $this->assertSame($first, $second);
        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $workOrder->id)->count());
    }

    /**
     * An HOA violation's tenant was sent the HOA link (it shows the deadline),
     * so that one is offered too when it exists.
     */
    public function test_an_existing_hoa_link_is_listed_next_to_the_general_one(): void
    {
        $workOrder = WorkOrder::factory()->create(['category' => WorkOrder::HOA_VIOLATION_CATEGORY]);

        $hoaToken = TenantUploadToken::query()->create([
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'token' => TenantUploadToken::generateUniqueToken(),
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
            ->assertOk()
            ->assertJsonCount(2, 'links')
            ->assertJsonPath('links.0.label', 'Tenant portal link')
            ->assertJsonPath('links.1.label', 'HOA violation link')
            ->assertJsonPath('links.1.url', route('tenant.portal.show', $hoaToken->token));
    }

    /**
     * Only the general token is ever created here: an HOA or easy-fix token
     * carries its own reminders, which a copy button must not start.
     */
    public function test_it_never_creates_an_hoa_or_easy_fix_token(): void
    {
        $workOrder = WorkOrder::factory()->create(['category' => WorkOrder::HOA_VIOLATION_CATEGORY]);

        $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
            ->assertOk()
            ->assertJsonCount(1, 'links');

        $this->assertSame(
            [TenantUploadToken::PURPOSE_WORK_ORDER],
            TenantUploadToken::query()->where('work_order_id', $workOrder->id)->pluck('purpose')->all(),
        );
    }

    /**
     * Each owner gets their own link, largest stake first, and an owner with
     * no phone is still listed: the coordinator may be emailing it.
     */
    public function test_staff_get_one_link_per_owner(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $minor = $this->owner('Mina', 25, null);
        $major = $this->owner('Olivia', 75);
        $workOrder->owners()->attach([$minor->id, $major->id]);

        $response = $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'owner']))
            ->assertOk()
            ->assertJsonCount(2, 'links')
            ->assertJsonPath('links.0.label', 'Olivia Owner')
            ->assertJsonPath('links.1.label', 'Mina Owner');

        $majorToken = OwnerPortalToken::query()->where(['work_order_id' => $workOrder->id, 'owner_id' => $major->id])->sole();
        $minorToken = OwnerPortalToken::query()->where(['work_order_id' => $workOrder->id, 'owner_id' => $minor->id])->sole();

        $response->assertJsonPath('links.0.url', route('owner.portal.show', $majorToken->token));
        $response->assertJsonPath('links.1.url', route('owner.portal.show', $minorToken->token));
    }

    public function test_an_owner_link_already_sent_is_reused(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $owner = $this->owner('Olivia', 100);
        $workOrder->owners()->attach($owner->id);

        $existing = OwnerPortalToken::query()->create([
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'token' => OwnerPortalToken::generateUniqueToken(),
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'owner']))
            ->assertOk()
            ->assertJsonPath('links.0.url', route('owner.portal.show', $existing->token));

        $this->assertSame(1, OwnerPortalToken::query()->count());
    }

    public function test_a_work_order_without_an_owner_returns_no_links(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'owner']))
            ->assertOk()
            ->assertExactJson(['links' => []]);
    }

    public function test_every_staff_role_can_copy_links(): void
    {
        $workOrder = WorkOrder::factory()->create();

        foreach (User::STAFF_ROLES as $role) {
            $this->actingAs($this->staff($role))
                ->postJson(route('work_orders.portal_links', [$workOrder, 'tenant']))
                ->assertOk();
        }
    }

    /**
     * A portal link is the key to that person's portal, so a vendor, owner or
     * tenant login must never be handed one - and no token is created for the
     * attempt.
     */
    public function test_non_staff_are_refused_and_nothing_is_created(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $owner = $this->owner('Olivia', 100);
        $workOrder->owners()->attach($owner->id);

        // A tenant login with no tenant record cannot see any work order
        // (WorkOrderScope fails closed), so it is a 404 before the staff check.
        $outsiders = [
            'vendor' => 403,
            'owner' => 403,
            'tenant' => 404,
            'no role' => 403,
        ];

        foreach ($outsiders as $role => $status) {
            $user = User::factory()->create();

            if ($role !== 'no role') {
                $user->assignRole($role);
            }

            foreach (['tenant', 'owner'] as $audience) {
                $this->actingAs($user)
                    ->postJson(route('work_orders.portal_links', [$workOrder->id, $audience]))
                    ->assertStatus($status);
            }
        }

        $this->assertSame(0, TenantUploadToken::query()->count());
        $this->assertSame(0, OwnerPortalToken::query()->count());
    }

    public function test_an_unknown_audience_is_rejected(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($this->staff())
            ->postJson(route('work_orders.portal_links', [$workOrder, 'vendor']))
            ->assertStatus(422);
    }

    public function test_guests_cannot_reach_it(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->postJson(route('work_orders.portal_links', [$workOrder->id, 'tenant']))
            ->assertUnauthorized();
    }
}
