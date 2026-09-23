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
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\OwnerMessageFormatter;
use App\Services\OwnerServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
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

    public function test_it_does_not_notify_a_property_with_no_lease_on_file(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $workOrder->owners()->attach($owner->id);

        // A PropertyWare work order with no lease attached is a vacant /
        // new-to-market home, so the "submitted by your tenant" confirmation
        // would be wrong for it.
        $workOrder->update(['propertyware_id' => 43485001, 'lease_id' => null]);

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

    /**
     * A work order with an address on file, entered in PropertyWare by our
     * team (Source anything but Tenant Portal or Website).
     */
    private function makeStaffCreatedWorkOrder(Owner $owner, string $source = 'None', ?string $description = 'There is water dripping from the roof down into the backyard'): WorkOrder
    {
        $building = Building::query()->firstOrCreate(
            ['propertyware_id' => 'B-6341DM'],
            ['name' => 'Del Monte', 'address' => '6341 Del Monte Dr', 'city' => 'Houston', 'state_region' => 'TX'],
        );
        $workOrder = $this->makeWorkOrder($this->makeTenant(), description: $description);
        $workOrder->update(['building_id' => $building->propertyware_id, 'source' => $source]);
        $workOrder->owners()->attach($owner->id);

        return $workOrder->fresh();
    }

    public function test_a_work_order_our_team_entered_gets_one_created_by_our_team_text(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $workOrder = $this->makeStaffCreatedWorkOrder($owner);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $messages = $workOrder->owner_conversation()->get();
        $this->assertCount(1, $messages);

        $text = $messages[0]->message;
        $this->assertStringContainsString('Hi Olivia Owner, a new work order #43361 has been created for 6341 Del Monte Dr by our team.', $text);
        $this->assertStringContainsString('Work Order Description: There is water dripping from the roof down into the backyard', $text);
        $this->assertStringContainsString("We'll keep you updated as the work progresses. Thank you!", $text);
        $this->assertStringContainsString(OwnerMessageFormatter::LINK_LEAD, $text);
        $this->assertStringContainsString('(Ref: WO#43361)', $text);
        $this->assertStringNotContainsString('received a new service request', $text);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertNotNull($workOrder->fresh()->owner_service_request_notified_at);

        // Same ledger key as the request-received pair, marked as this shape.
        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'owner_service_request_sms')
            ->firstOrFail();
        $this->assertSame('staff_created', $ledger->properties['variant']);
    }

    public function test_a_tenant_portal_or_website_request_keeps_the_request_received_pair(): void
    {
        $this->enableGate();
        Queue::fake();

        foreach (['Tenant Portal', 'Website'] as $source) {
            $owner = $this->makeOwner('3466260693', 100);
            $workOrder = $this->makeStaffCreatedWorkOrder($owner, $source);

            app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

            $messages = $workOrder->owner_conversation()->orderBy('id')->get();
            $this->assertCount(2, $messages, "Source [{$source}] should keep the two-text confirmation.");
            $this->assertStringContainsString('received a new service request', $messages[0]->message);
            $this->assertStringNotContainsString('by our team', $messages[0]->message);
        }
    }

    public function test_the_created_text_omits_the_description_line_when_there_is_none(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $workOrder = $this->makeStaffCreatedWorkOrder($owner, description: null);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $text = $workOrder->owner_conversation()->firstOrFail()->message;
        $this->assertStringNotContainsString('Work Order Description', $text);
        $this->assertStringContainsString("by our team.\n\nOur team will review", $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function test_the_created_text_caps_a_long_description_and_straightens_smart_punctuation(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        // Pasted from Word: curly apostrophe and an em dash, then far more
        // text than a single SMS should carry.
        $description = "The tenant\u{2019}s upstairs bathroom \u{2014} ".str_repeat('water keeps pooling by the tub ', 60);
        $workOrder = $this->makeStaffCreatedWorkOrder($owner, description: $description);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $text = $workOrder->owner_conversation()->firstOrFail()->message;
        $this->assertStringContainsString("Work Order Description: The tenant's upstairs bathroom - water keeps", $text);
        $this->assertStringContainsString('...', $text);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));
        $this->assertLessThan(1600, strlen($text));
        // The cap applies to the description, not the whole text.
        $this->assertStringContainsString("We'll keep you updated", $text);
    }

    public function test_the_created_text_says_your_property_when_no_address_is_known(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $workOrder->update(['source' => 'Telephone']);
        $workOrder->owners()->attach($owner->id);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder->fresh());

        $text = $workOrder->owner_conversation()->firstOrFail()->message;
        $this->assertStringContainsString('has been created for your property by our team.', $text);
    }

    public function test_the_created_text_greets_an_owner_by_the_name_on_file_or_plainly(): void
    {
        $this->enableGate();
        Queue::fake();

        // An LLC: PropertyWare holds the company as the owner's name.
        $company = $this->makeOwner('3466260693', 100);
        $company->update(['name' => 'Del Monte Holdings LLC']);
        $workOrder = $this->makeStaffCreatedWorkOrder($company);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertStringContainsString('Hi Del Monte Holdings LLC, a new work order', $workOrder->owner_conversation()->firstOrFail()->message);

        // No name at all.
        $nameless = $this->makeOwner('2810000001', 100);
        $nameless->update(['first_name' => '', 'last_name' => '']);
        $workOrder = $this->makeStaffCreatedWorkOrder($nameless);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $this->assertStringContainsString('Hi, a new work order', $workOrder->owner_conversation()->firstOrFail()->message);
    }

    public function test_a_turnover_our_team_entered_is_still_skipped(): void
    {
        $this->enableGate();
        Queue::fake();

        $owner = $this->makeOwner('3466260693', 100);
        $workOrder = $this->makeStaffCreatedWorkOrder($owner, 'Inspection');
        $workOrder->update(['type' => 'Turnover']);

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder->fresh());

        $this->assertSame(0, $workOrder->owner_conversation()->count());
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertNull($workOrder->fresh()->owner_service_request_notified_at);
    }

    // --- Tenant easy fix ---

    /**
     * The easy-fix texts on, the disposal item given a video, and the tenant
     * intake text on so the tenant counts as reachable.
     */
    private function enableEasyFix(): void
    {
        $this->enableGate();
        config([
            'services.twilio.tenant_intake_sms' => true,
            'services.twilio.tenant_easy_fix_sms' => true,
        ]);

        $items = config('tenant_easy_fix.items');

        foreach ($items as $index => $item) {
            if ($item['key'] === 'disposal_jammed') {
                $items[$index]['video_url'] = 'https://youtu.be/disposal';
            }
        }

        config(['tenant_easy_fix.items' => $items]);
    }

    /**
     * A tenant-portal disposal request with one owner and an address.
     */
    private function makeDisposalWorkOrder(?Tenants $tenant = null, array $attributes = [], ?array $includedAppliances = null): WorkOrder
    {
        $owner = $this->makeOwner('3466260693', 100);
        $tenant ??= $this->makeTenant();
        $building = Building::query()->create([
            'propertyware_id' => 'B-6341DM-'.uniqid(),
            'name' => 'Del Monte',
            'address' => '6341 Del Monte Dr',
            'city' => 'Houston',
            'state_region' => 'TX',
            'custom_fields' => $includedAppliances,
        ]);
        $workOrder = $this->makeWorkOrder($tenant, null, 'Garbage disposal is humming but not turning');
        $workOrder->update(array_merge([
            'building_id' => $building->propertyware_id,
            'source' => 'Tenant Portal',
            'propertyware_id' => 43361001,
            'lease_id' => 555001,
            'category' => 'Garbage Disposal',
        ], $attributes));
        $workOrder->owners()->attach($owner->id);

        return $workOrder->fresh();
    }

    public function test_the_owner_is_told_the_tenant_got_the_how_to_instead_of_an_estimate(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder();

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $messages = $workOrder->owner_conversation()->orderBy('id')->get();
        $this->assertCount(2, $messages);

        $confirmation = $messages[0]->message;
        $this->assertStringContainsString('received a new service request for your property at 6341 Del Monte Dr (request #43361)', $confirmation);
        $this->assertStringContainsString('normally a tenant easy fix (garbage disposal)', $confirmation);
        $this->assertStringContainsString('sent the tenant a how-to video and asked them to try it first', $confirmation);
        $this->assertStringNotContainsString('take care of arranging the estimate', $confirmation);
        $this->assertStringContainsString('(Ref: WO#43361)', $confirmation);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($confirmation));

        // The description text still follows.
        $this->assertStringContainsString('details of the request', $messages[1]->message);
        $this->assertStringContainsString('humming but not turning', $messages[1]->message);

        Queue::assertPushed(SendConversationMessageJob::class, 2);

        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'owner_easy_fix_sms')
            ->firstOrFail();
        $this->assertSame('disposal_jammed', $ledger->properties['easy_fix_key']);
        $this->assertSame(1, Activity::query()->where('event', 'owner_service_request_sms')->count());
    }

    public function test_the_owner_keeps_the_estimate_wording_when_the_tenant_cannot_be_told(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        // Easy-fix gate off: nothing was sent to the tenant.
        config(['services.twilio.tenant_easy_fix_sms' => false]);
        $gateOff = $this->makeDisposalWorkOrder();
        app(OwnerServiceRequestNotificationService::class)->notify($gateOff);
        $this->assertStringContainsString('take care of arranging the estimate', $gateOff->owner_conversation()->orderBy('id')->first()->message);
        config(['services.twilio.tenant_easy_fix_sms' => true]);

        // No way to reach the tenant: no phone, placeholder email.
        $unreachable = Tenants::query()->create([
            'first_name' => 'Terry',
            'last_name' => 'Tenant',
            'email' => 'placeholder@texasrenter.com',
            'mobile_phone' => null,
            'user_id' => User::factory()->create()->id,
        ]);
        $noPhone = $this->makeDisposalWorkOrder($unreachable);
        app(OwnerServiceRequestNotificationService::class)->notify($noPhone);
        $this->assertStringContainsString('take care of arranging the estimate', $noPhone->owner_conversation()->orderBy('id')->first()->message);

        // Tenant automation muted on the work order.
        $muted = $this->makeDisposalWorkOrder();
        $muted->setAutomationPaused('tenant', true);
        app(OwnerServiceRequestNotificationService::class)->notify($muted->fresh());
        $this->assertStringContainsString('take care of arranging the estimate', $muted->owner_conversation()->orderBy('id')->first()->message);

        $this->assertSame(0, Activity::query()->where('event', 'owner_easy_fix_sms')->count());
    }

    public function test_the_owner_is_told_the_washer_is_a_non_realty_item_and_asked_whether_to_cover_it(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder(
            null,
            ['description' => 'Our washing machine will not spin', 'category' => 'Washer'],
            [['fieldName' => 'Included Appliances', 'value' => 'refrigerator', 'dataType' => 'Text']],
        );

        app(OwnerServiceRequestNotificationService::class)->notify($workOrder);

        $messages = $workOrder->owner_conversation()->orderBy('id')->get();
        $this->assertCount(2, $messages);

        $confirmation = $messages[0]->message;
        $this->assertStringContainsString('It concerns the washer. Under the lease the washer is a non-realty property item provided as-is', $confirmation);
        $this->assertStringContainsString('Reply YES if you would like us to arrange the repair or replacement at your cost, or NO to leave it with the tenant', $confirmation);
        $this->assertStringNotContainsString('take care of arranging the estimate', $confirmation);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($confirmation));

        $this->assertSame(1, Activity::query()->where('event', 'owner_appliance_responsibility_sms')->count());
    }
}
