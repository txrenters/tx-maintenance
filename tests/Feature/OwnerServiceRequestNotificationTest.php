<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Models\Building;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
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
        $tenant = $this->makeTenant('6341 Del Monte Dr');
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $messages = $workOrder->owner_conversation()->orderBy('id')->get();
        $this->assertCount(2, $messages);

        // Message 1: confirmation with WO# + property address, from Chana's wording.
        $confirmation = $messages[0]->message;
        $this->assertStringContainsString('email copy of the service request submitted by your tenant', $confirmation);
        $this->assertStringContainsString('service request number 43361', $confirmation);
        $this->assertStringContainsString('property address 6341 Del Monte Dr', $confirmation);
        $this->assertStringContainsString('(Ref: WO#43361)', $confirmation);

        // Message 2: the description as its own follow-up text.
        $description = $messages[1]->message;
        $this->assertStringContainsString('Description', $description);
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

        // WOC has marked the unit vacant: there is no tenant who "submitted"
        // the request, so the owner confirmation must not be sent at all.
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

        // Turnover units are vacant — no tenant "submitted" the request, so the
        // owner confirmation must not be sent, even without the manual toggle.
        $workOrder->update(['type' => 'Turnover']);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

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
        $this->assertStringContainsString('property address 500 Elm St', $confirmation);
    }

    public function test_it_uses_a_neutral_phrase_when_no_address_is_available(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant('');
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $confirmation = $workOrder->owner_conversation()->first()->message;
        $this->assertStringContainsString('property address your property', $confirmation);
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
