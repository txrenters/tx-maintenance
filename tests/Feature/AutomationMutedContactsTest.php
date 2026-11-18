<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerServiceRequestNotificationService;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AutomationMutedContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function makeOwner(string $first, ?string $mobile, int $ownership = 100, bool $muted = false): Owner
    {
        return Owner::query()->create([
            'first_name' => $first,
            'last_name' => 'Owner',
            'email' => strtolower($first).'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => $ownership,
            'user_id' => User::factory()->create()->id,
            'automations_muted_at' => $muted ? now() : null,
        ]);
    }

    private function makeTenant(string $first, string $mobile, bool $muted = false): Tenants
    {
        return Tenants::query()->create([
            'first_name' => $first,
            'last_name' => 'Tenant',
            'email' => strtolower($first).'@example.com',
            'mobile_phone' => $mobile,
            'address' => '6341 Del Monte Dr',
            'user_id' => User::factory()->create()->id,
            'automations_muted_at' => $muted ? now() : null,
        ]);
    }

    private function makeWorkOrder(?Tenants $requester = null): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->first() ?? ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 43361,
            'description' => 'There is water dripping from the roof',
            'tenant_id' => $requester?->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Recipient selection
    |--------------------------------------------------------------------------
    */

    public function test_a_muted_owner_is_left_out_of_notifiable_owners(): void
    {
        $workOrder = $this->makeWorkOrder();
        $muted = $this->makeOwner('Thomas', '7135698343', 100, muted: true);
        $coOwner = $this->makeOwner('Carla', '3466260693', 0);
        $workOrder->owners()->attach([$muted->id, $coOwner->id]);

        $owners = $workOrder->fresh()->notifiableOwners();

        $this->assertSame([$coOwner->id], $owners->pluck('id')->all());
    }

    public function test_a_muted_requester_is_skipped_but_housemates_still_hear(): void
    {
        $requester = $this->makeTenant('Dana', '5125559999', muted: true);
        $housemate = $this->makeTenant('Eli', '5125558888');
        $workOrder = $this->makeWorkOrder($requester);
        $workOrder->tenants()->attach([$requester->id, $housemate->id]);

        $fresh = $workOrder->fresh();
        $recipients = $fresh->tenantIntakeRecipients(fn (Tenants $tenant): bool => $fresh->normalizedTenantPhone($tenant) !== null);

        $this->assertSame([$housemate->id], $recipients->pluck('id')->all());
        $this->assertTrue($fresh->requesterAutomationsMuted());
    }

    public function test_a_requester_who_is_not_muted_is_not_reported_as_muted(): void
    {
        $this->assertFalse($this->makeWorkOrder($this->makeTenant('Dana', '5125559999'))->requesterAutomationsMuted());
        $this->assertFalse($this->makeWorkOrder()->requesterAutomationsMuted());
    }

    /*
    |--------------------------------------------------------------------------
    | Senders end to end
    |--------------------------------------------------------------------------
    */

    public function test_the_owner_intake_text_skips_a_muted_owner_and_still_reaches_the_co_owner(): void
    {
        config(['services.twilio.owner_service_request_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant('Dana', '5125559999'));
        $muted = $this->makeOwner('Thomas', '(713) 569-8343', 100, muted: true);
        $coOwner = $this->makeOwner('Carla', '3466260693', 0);
        $workOrder->owners()->attach([$muted->id, $coOwner->id]);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $receivers = $workOrder->owner_conversation()->pluck('receiver_number')->unique()->values()->all();
        $this->assertSame(['+13466260693'], $receivers);
        Queue::assertNotPushed(SendConversationMessageJob::class, fn (SendConversationMessageJob $job): bool => str_contains(json_encode((array) $job), '7135698343'));
    }

    public function test_the_owner_intake_text_sends_nothing_when_the_only_owner_is_muted(): void
    {
        config(['services.twilio.owner_service_request_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant('Dana', '5125559999'));
        $workOrder->owners()->attach($this->makeOwner('Thomas', '7135698343', 100, muted: true)->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_the_tenant_intake_text_sends_nothing_to_a_muted_requester(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $requester = $this->makeTenant('Dana', '5125559999', muted: true);
        $workOrder = $this->makeWorkOrder($requester);

        app(TenantServiceRequestNotificationService::class)->notify($workOrder);

        Queue::assertNotPushed(SendConversationMessageJob::class);

        // Control: the same work order texts the requester once unmuted.
        $requester->setAutomationsMuted(false);
        $workOrder = $workOrder->fresh();
        $workOrder->forceFill(['tenant_service_request_notified_at' => null])->save();

        app(TenantServiceRequestNotificationService::class)->notify($workOrder);

        Queue::assertPushed(SendConversationMessageJob::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Do Not Message tab
    |--------------------------------------------------------------------------
    */

    public function test_the_list_shows_only_muted_owners_and_tenants(): void
    {
        $this->makeOwner('Thomas', '7135698343', muted: true);
        $this->makeOwner('Carla', '3466260693');
        $this->makeTenant('Dana', '5125559999', muted: true);

        $this->actingAs($this->staff('woc'))
            ->getJson(route('it-tools.automated-messages.muted'))
            ->assertOk()
            ->assertJsonCount(2, 'contacts')
            ->assertJsonPath('contacts.0.type', 'owner')
            ->assertJsonPath('contacts.0.name', 'Thomas Owner')
            ->assertJsonPath('contacts.0.muted', true)
            ->assertJsonPath('contacts.1.type', 'tenant')
            ->assertJsonPath('contacts.1.name', 'Dana Tenant');
    }

    public function test_search_finds_a_formatted_phone_number_from_plain_digits(): void
    {
        $this->makeOwner('Thomas', '(713) 569-8343');
        $this->makeOwner('Carla', '3466260693');

        $this->actingAs($this->staff())
            ->getJson(route('it-tools.automated-messages.muted.search', ['q' => '7135698343']))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.name', 'Thomas Owner');
    }

    public function test_search_matches_owners_and_tenants_by_full_name(): void
    {
        $this->makeOwner('Thomas', '7135698343');
        $this->makeTenant('Thomas', '5125559999');
        $this->makeTenant('Dana', '5125558888');

        $this->actingAs($this->staff())
            ->getJson(route('it-tools.automated-messages.muted.search', ['q' => 'thomas owner']))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.type', 'owner');

        $this->actingAs($this->staff())
            ->getJson(route('it-tools.automated-messages.muted.search', ['q' => 'Thomas']))
            ->assertOk()
            ->assertJsonCount(2, 'contacts');
    }

    public function test_staff_can_mute_and_unmute_an_owner(): void
    {
        $owner = $this->makeOwner('Thomas', '7135698343');
        $staff = $this->staff('woc');

        $this->actingAs($staff)
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'owner', 'id' => $owner->id]), ['muted' => true])
            ->assertOk()
            ->assertJsonPath('contact.muted', true);

        $this->assertTrue($owner->fresh()->automationsMuted());

        $this->actingAs($staff)
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'owner', 'id' => $owner->id]), ['muted' => false])
            ->assertOk()
            ->assertJsonPath('contact.muted', false);

        $this->assertFalse($owner->fresh()->automationsMuted());
    }

    public function test_staff_can_mute_a_tenant(): void
    {
        $tenant = $this->makeTenant('Dana', '5125559999');

        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'tenant', 'id' => $tenant->id]), ['muted' => true])
            ->assertOk();

        $this->assertTrue($tenant->fresh()->automationsMuted());
    }

    public function test_muting_twice_keeps_the_original_muted_date(): void
    {
        $owner = $this->makeOwner('Thomas', '7135698343', muted: true);
        $owner->forceFill(['automations_muted_at' => now()->subWeek()])->save();
        $originally = $owner->fresh()->automations_muted_at;

        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'owner', 'id' => $owner->id]), ['muted' => true])
            ->assertOk();

        $this->assertTrue($originally->equalTo($owner->fresh()->automations_muted_at));
    }

    public function test_an_unknown_contact_type_is_not_found(): void
    {
        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'vendor', 'id' => 1]), ['muted' => true])
            ->assertNotFound();
    }

    public function test_non_staff_cannot_see_or_change_the_list(): void
    {
        $owner = $this->makeOwner('Thomas', '7135698343');
        $user = $this->staff('accounting');

        $this->actingAs($user)->getJson(route('it-tools.automated-messages.muted'))->assertForbidden();
        $this->actingAs($user)->getJson(route('it-tools.automated-messages.muted.search', ['q' => 'Thomas']))->assertForbidden();
        $this->actingAs($user)
            ->putJson(route('it-tools.automated-messages.muted.update', ['type' => 'owner', 'id' => $owner->id]), ['muted' => true])
            ->assertForbidden();

        $this->assertFalse($owner->fresh()->automationsMuted());
    }
}
