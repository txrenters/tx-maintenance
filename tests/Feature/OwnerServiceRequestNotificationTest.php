<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Models\Building;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OwnerServiceRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(?string $mobile, int $ownership): Owner
    {
        return Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'email' => 'owner'.$ownership.'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => $ownership,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeTenant(string $address = '6341 Del Monte Dr'): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Terry',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'address' => $address,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(?Tenants $tenant, ?Owner $managementCompany = null, ?string $description = 'There is water dripping from the roof down into the backyard'): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->first() ?? ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 43361,
            'description' => $description,
            'tenant_id' => $tenant?->id,
            'property_manager_id' => $managementCompany?->id,
        ]);
    }

    private function enableGate(): void
    {
        config(['services.twilio.owner_service_request_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
    }

    public function test_it_texts_the_owner_a_confirmation_and_a_description(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $building = Building::query()->create([
            'propertyware_id' => 'B-6341DM',
            'name' => 'Del Monte',
            'address' => '6341 Del Monte Dr',
            'city' => 'Houston',
            'state_region' => 'TX',
        ]);
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->update(['building_id' => $building->propertyware_id]);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $messages = $workOrder->owner_conversation()->orderBy('id')->get();
        $this->assertCount(2, $messages);

        // Message 1: confirmation with WO# + property address, from Chana's wording.
        $confirmation = $messages[0]->message;
        $this->assertStringContainsString('received a new service request', $confirmation);
        $this->assertStringContainsString('(request #43361)', $confirmation);
        $this->assertStringContainsString('6341 Del Monte Dr', $confirmation);
        // The PropertyWare email and portal are no longer referenced: that email
        // is not sent by this app, so we cannot confirm the owner received it.
        $this->assertStringNotContainsString('email copy', $confirmation);
        $this->assertStringNotContainsString('log in to your owner portal', $confirmation);
        $this->assertStringContainsString('(Ref: WO#43361)', $confirmation);

        // Message 2: the description as its own follow-up text.
        $description = $messages[1]->message;
        $this->assertStringContainsString('details of the request', $description);
        $this->assertStringContainsString('water dripping from the roof', $description);
        $this->assertStringContainsString('(Ref: WO#43361)', $description);

        // Both go to the owner, sent from the maintenance line.
        $this->assertSame('+13466260693', $messages[0]->receiver_number);
        $this->assertSame('+12813787957', $messages[0]->sender_number);

        Queue::assertPushed(SendConversationMessageJob::class, 2);
        $this->assertNotNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_sends_only_the_confirmation_when_there_is_no_description(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant, null, description: null);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(1, $workOrder->owner_conversation()->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_it_texts_the_primary_owner_not_the_management_company(): void
    {
        $this->enableGate();
        Queue::fake();

        // property_manager_id / managed_by points at the management company (0% stake).
        $managementCompany = $this->makeOwner('2810000000', 0);
        $realOwner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant, $managementCompany);
        $workOrder->owners()->attach([$managementCompany->id, $realOwner->id]);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame('+13466260693', $workOrder->owner_conversation()->first()->receiver_number);
    }

    public function test_it_is_silent_when_disabled(): void
    {
        // Gate defaults to off.
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_when_the_property_is_vacant(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        // A vacant unit's work is already set in motion by the company or the
        // owner, so the "new service request" confirmation is skipped
        // (WO#43729). The stamp stays clear so correcting the flag re-arms it.
        $workOrder->update(['skip_automated_tasks' => true]);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_on_a_turnover_work_order(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        $workOrder->update(['type' => 'Turnover']);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_on_a_rekey_work_order(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        $workOrder->update(['category' => 'Re-key']);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_on_a_refresh_cleaning_work_order(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        $workOrder->update(['category' => 'Cleaning']);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_on_a_categorized_hoa_violation(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        // A violation notice is not a tenant-submitted repair request — dumping
        // its items and remedies into the intake text confuses the owner.
        $workOrder->update(['category' => WorkOrder::HOA_VIOLATION_CATEGORY]);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_does_not_notify_on_an_hoa_violation_created_from_an_upload(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        // Uploaded notices carry the HOA token instead of the category.
        TenantUploadToken::query()->create([
            'work_order_id' => $workOrder->id,
            'token' => 'hoa-test-token',
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder->fresh());

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_notifies_at_most_once_per_work_order(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        $service = app(OwnerServiceRequestNotificationService::class);
        $service->notify($workOrder);
        $service->notify($workOrder);

        $this->assertSame(2, $workOrder->owner_conversation()->count());
        Queue::assertPushed(SendConversationMessageJob::class, 2);
    }

    public function test_an_owner_with_no_phone_is_not_texted(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner(null, 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    public function test_it_uses_the_building_address_over_a_mismatched_tenant_contact_address(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        // The requested-by contact's PropertyWare mailing address points somewhere
        // else entirely (WO#43596), so it must never win over the building.
        $tenant = $this->makeTenant('2514 Rose Gold Dr');
        $building = Building::query()->create([
            'propertyware_id' => 'B-2808AB',
            'name' => 'Arbor Brook',
            'address' => '2808 Arbor Brook Ln',
            'city' => 'Pearland',
            'state_region' => 'TX',
        ]);
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->update(['building_id' => $building->propertyware_id]);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = $workOrder->owner_conversation()->first()->message;
        $this->assertStringContainsString('your property at 2808 Arbor Brook Ln', $confirmation);
        $this->assertStringNotContainsString('2514 Rose Gold Dr', $confirmation);
    }

    public function test_it_falls_back_to_the_building_street_address_when_the_tenant_has_none(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        // Tenant on file but with no address.
        $tenant = $this->makeTenant('');
        $building = Building::query()->create([
            'propertyware_id' => 'B-990099',
            'name' => 'Demo Building',
            'address' => '500 Elm St',
            'city' => 'Houston',
            'state_region' => 'TX',
        ]);
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->update(['building_id' => $building->propertyware_id]);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = $workOrder->owner_conversation()->first()->message;
        $this->assertStringContainsString('your property at 500 Elm St', $confirmation);
    }

    public function test_it_uses_a_neutral_phrase_when_no_building_is_known_even_if_the_tenant_has_an_address(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        // The contact's mailing address must never leak into the message when
        // the work order has no building on file (WO#43517).
        $tenant = $this->makeTenant('1600 Clark Blvd');
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = $workOrder->owner_conversation()->first()->message;
        $this->assertStringContainsString('for your property (request #', $confirmation);
        $this->assertStringNotContainsString('1600 Clark Blvd', $confirmation);
    }

    public function test_it_falls_back_to_the_building_name_when_the_building_has_no_address(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant('1600 Clark Blvd');
        $building = Building::query()->create([
            'propertyware_id' => 'B-1532A',
            'name' => '1532A',
            'address' => '',
        ]);
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->update(['building_id' => $building->propertyware_id]);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = $workOrder->owner_conversation()->first()->message;
        $this->assertStringContainsString('your property at 1532A', $confirmation);
        $this->assertStringNotContainsString('1600 Clark Blvd', $confirmation);
    }

    public function test_the_job_runs_the_service(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        (new SendOwnerServiceRequestNotificationJob($workOrder->id))
            ->handle(app(OwnerServiceRequestNotificationService::class));

        $this->assertSame(2, $workOrder->owner_conversation()->count());
    }
}
