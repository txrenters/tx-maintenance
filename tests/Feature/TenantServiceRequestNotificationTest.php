<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\PropertyWareService;
use App\Services\TenantEasyFixService;
use App\Services\TenantMessageFormatter;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TenantServiceRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate starts off; a deterministic "from" number so the send fires.
        config([
            'services.twilio.tenant_intake_sms' => false,
            'services.twilio.maintenance_from' => '+12813787957',
        ]);
    }

    private function makeTenant(?string $phone = '5125559999'): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => $phone,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(array $attributes = [], ?Tenants $tenant = null): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'work_order_no' => 43900,
            'description' => 'Water heater is leaking in the garage',
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ], $attributes));
    }

    private function notify(WorkOrder $workOrder): void
    {
        app(TenantServiceRequestNotificationService::class)->notify($workOrder);
    }

    private function tenantMessage(WorkOrder $workOrder): ?Conversation
    {
        return Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->first();
    }

    public function test_it_texts_the_tenant_that_the_request_was_received(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder();

        $this->notify($workOrder);

        $message = $this->tenantMessage($workOrder);

        $this->assertNotNull($message);
        $this->assertStringContainsString('Hi Dana,', $message->message);
        $this->assertStringContainsString('we have received your service request', $message->message);
        $this->assertStringContainsString('if needed, the owner', $message->message);
        $this->assertSame('+15125559999', $message->receiver_number);
        $this->assertSame('+12813787957', $message->sender_number);

        Queue::assertPushed(SendConversationMessageJob::class);
    }

    public function test_it_carries_the_tenant_portal_link(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder();

        $this->notify($workOrder);

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->firstOrFail();

        $this->assertStringContainsString(
            route('tenant.portal.show', $token->token),
            $this->tenantMessage($workOrder)->message,
        );
    }

    public function test_it_references_the_work_order_number(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder();

        $this->notify($workOrder);

        $this->assertStringContainsString('(Ref: WO#43900)', $this->tenantMessage($workOrder)->message);
    }

    public function test_it_does_nothing_when_the_gate_is_off(): void
    {
        Queue::fake();

        $workOrder = $this->makeWorkOrder();

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        Queue::assertNothingPushed();
    }

    public function test_it_sends_only_once_per_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder();

        $this->notify($workOrder);
        $this->notify($workOrder->fresh());

        $this->assertSame(1, Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->count());

        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_it_skips_hoa_violations(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['category' => WorkOrder::HOA_VIOLATION_CATEGORY]);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        Queue::assertNothingPushed();
    }

    public function test_it_skips_a_turnover_type_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // WO#43729: a turnover has no tenant awaiting repairs — the requested-by
        // contact replied "I have no request" to this text.
        $workOrder = $this->makeWorkOrder(['type' => 'Turnover']);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        // Left un-stamped so correcting the type re-arms the notification.
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        Queue::assertNothingPushed();
    }

    public function test_it_skips_a_turnover_category_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['category' => 'Turnover']);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        Queue::assertNothingPushed();
    }

    public function test_it_skips_a_rekey_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // PropertyWare writes the value with varying hyphens and casing, so
        // both spellings must hit the same skip.
        foreach ([['category' => 'Re-key'], ['type' => 'Re-Key']] as $attributes) {
            $workOrder = $this->makeWorkOrder($attributes);

            $this->notify($workOrder);

            $this->assertNull($this->tenantMessage($workOrder));
            $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        }

        Queue::assertNothingPushed();
    }

    public function test_it_skips_refresh_and_cleaning_work_orders(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        foreach (['Cleaning', 'Make ready', 'carpet Steam clean'] as $category) {
            $workOrder = $this->makeWorkOrder(['category' => $category]);

            $this->notify($workOrder);

            $this->assertNull($this->tenantMessage($workOrder), "Category {$category} should be opted out.");
            $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);

            // Messages-only opt-out: a cleaning can happen in an occupied home,
            // so these must NOT count as vacant (vendors keep tenant contact).
            $this->assertFalse($workOrder->isVacant());
            $this->assertTrue($workOrder->skipsAutomatedMessages());
        }

        Queue::assertNothingPushed();
    }

    public function test_it_skips_a_work_order_marked_vacant(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['skip_automated_tasks' => true]);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        Queue::assertNothingPushed();
    }

    public function test_it_respects_the_per_work_order_automation_pause(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder();
        $workOrder->setAutomationPaused('tenant', true);

        $this->notify($workOrder->fresh());

        $this->assertNull($this->tenantMessage($workOrder));
        Queue::assertNothingPushed();
    }

    public function test_it_skips_a_tenant_with_no_phone_number(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder([], $this->makeTenant(null));

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        Queue::assertNothingPushed();
    }

    public function test_it_omits_the_address_clause_when_there_is_no_address(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['building_id' => null]);

        $this->notify($workOrder);

        $this->assertStringContainsString(
            'we have received your service request.',
            $this->tenantMessage($workOrder)->message,
        );
    }

    public function test_it_skips_a_propertyware_work_order_with_no_lease_on_file(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // Imported from PropertyWare with no lease attached: the property is
        // vacant / new to market, and requested_by is a leasing agent or
        // former occupant rather than a tenant awaiting repairs (WO#43485).
        $workOrder = $this->makeWorkOrder(['propertyware_id' => 43485001, 'lease_id' => null]);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);

        // Messages-only opt-out, like refresh/cleaning: vendors and owners
        // keep their usual content, only the automated messages stop.
        $this->assertFalse($workOrder->isVacant());
        $this->assertTrue($workOrder->skipsAutomatedMessages());

        Queue::assertNothingPushed();
    }

    public function test_a_lease_or_lease_tenants_keep_the_messages_flowing(): void
    {
        // A lease id alone is proof of an occupied home...
        $leased = $this->makeWorkOrder(['propertyware_id' => 43485002, 'lease_id' => 555001]);
        $this->assertFalse($leased->hasNoLeaseOnFile());

        // ...as is the lease-tenant roster the import fills in.
        $rostered = $this->makeWorkOrder(['propertyware_id' => 43485003, 'lease_id' => null]);
        $rostered->tenants()->attach($this->makeTenant()->id);
        $this->assertFalse($rostered->hasNoLeaseOnFile());

        // A local-only row never came from PropertyWare, so it cannot claim
        // PropertyWare reported no lease.
        $local = $this->makeWorkOrder(['propertyware_id' => null, 'lease_id' => null]);
        $this->assertFalse($local->hasNoLeaseOnFile());
    }

    public function test_app_created_work_orders_are_exempt_from_the_lease_check(): void
    {
        // Tenant portal requests are stamped at intake and HOA violations are
        // recognised by isHoaViolation(); neither ever carries a PW lease, so
        // the lease check must not silence them.
        $portal = $this->makeWorkOrder(['propertyware_id' => 43485004, 'source' => 'Tenant Portal']);
        $this->assertFalse($portal->hasNoLeaseOnFile());

        $hoa = $this->makeWorkOrder(['propertyware_id' => 43485005, 'category' => WorkOrder::HOA_VIOLATION_CATEGORY]);
        $this->assertFalse($hoa->hasNoLeaseOnFile());
    }

    /**
     * A work order with an address on file, entered in PropertyWare by our
     * team (Source anything but Tenant Portal or Website).
     */
    private function makeStaffCreatedWorkOrder(array $attributes = []): WorkOrder
    {
        $building = Building::query()->firstOrCreate(
            ['propertyware_id' => 'B-500ELM'],
            ['name' => 'Elm', 'address' => '500 Elm St', 'city' => 'Houston', 'state_region' => 'TX'],
        );

        return $this->makeWorkOrder(array_merge([
            'source' => 'None',
            'building_id' => $building->propertyware_id,
        ], $attributes));
    }

    public function test_a_work_order_our_team_entered_tells_the_tenant_it_was_created_for_them(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeStaffCreatedWorkOrder();

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('Hi Dana, a work order #43900 has been created for 500 Elm St by our team.', $text);
        $this->assertStringContainsString('Work Order Description: Water heater is leaking in the garage', $text);
        $this->assertStringContainsString("We'll contact you regarding scheduling or access if needed. Thank you!", $text);
        $this->assertStringContainsString(TenantMessageFormatter::LINK_LEAD, $text);
        $this->assertStringContainsString('(Ref: WO#43900)', $text);
        $this->assertStringNotContainsString('received your service request', $text);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertNotNull($workOrder->fresh()->tenant_service_request_notified_at);

        // Same ledger key as the request-received text, marked as this shape.
        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'tenant_service_request_sms')
            ->firstOrFail();
        $this->assertSame('staff_created', $ledger->properties['variant']);
    }

    public function test_the_tenant_channels_and_an_unknown_source_keep_the_request_received_wording(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        foreach (['Tenant Portal', 'Website', null] as $source) {
            $workOrder = $this->makeStaffCreatedWorkOrder(['source' => $source]);

            $this->notify($workOrder);

            $text = $this->tenantMessage($workOrder)->message;
            $this->assertStringContainsString('we have received your service request for 500 Elm St', $text, 'Source ['.var_export($source, true).']');
            $this->assertStringNotContainsString('by our team', $text);
        }
    }

    public function test_the_created_text_says_your_home_when_no_address_is_known(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['source' => 'Inspection', 'building_id' => null]);

        $this->notify($workOrder);

        $this->assertStringContainsString(
            'has been created for your home by our team.',
            $this->tenantMessage($workOrder)->message,
        );
    }

    public function test_the_created_text_omits_the_description_line_when_there_is_none(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeStaffCreatedWorkOrder(['description' => '']);

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringNotContainsString('Work Order Description', $text);
        $this->assertStringContainsString("by our team.\n\nOur team will review", $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function test_the_created_text_is_sent_once_per_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeStaffCreatedWorkOrder();

        $this->notify($workOrder);
        $this->notify($workOrder->fresh());

        $this->assertSame(1, Conversation::query()->where('work_order_id', $workOrder->id)->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_an_hoa_notice_our_team_entered_still_gets_no_text(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeStaffCreatedWorkOrder(['category' => WorkOrder::HOA_VIOLATION_CATEGORY]);

        $this->notify($workOrder);

        $this->assertNull($this->tenantMessage($workOrder));
        Queue::assertNothingPushed();
    }

    /**
     * A contact, named, with or without a number — the requester or a lease
     * tenant. Lease tenants are attached to the roster (work_order_tenants)
     * the PropertyWare import fills in.
     */
    private function makeContact(string $firstName, ?string $phone, ?WorkOrder $onLeaseOf = null, ?string $propertywareId = null): Tenants
    {
        $tenant = Tenants::query()->create([
            'first_name' => $firstName,
            'last_name' => 'Contact',
            'email' => strtolower($firstName).uniqid().'@example.com',
            'mobile_phone' => $phone,
            'propertyware_id' => $propertywareId,
            'user_id' => User::factory()->create()->id,
        ]);

        $onLeaseOf?->tenants()->attach($tenant->id);

        return $tenant;
    }

    /**
     * WO#44111's shape: an inspection finding whose Requested By is the
     * technician who logged it, with no phone, on a home with two tenants on
     * the lease.
     */
    private function makeInspectionWorkOrderWithLeaseTenants(?string $technicianPhone = null): WorkOrder
    {
        $technician = $this->makeContact('Moses', $technicianPhone);
        $workOrder = $this->makeStaffCreatedWorkOrder(['source' => 'Inspection', 'tenant_id' => $technician->id]);
        $this->makeContact('Forrest', '7132526614', $workOrder);
        $this->makeContact('Marisol', '5712140948', $workOrder);

        return $workOrder;
    }

    /**
     * @return array<int, Conversation>
     */
    private function tenantMessages(WorkOrder $workOrder): array
    {
        return Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'tenant')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_the_lease_tenants_are_texted_when_the_requester_is_not_on_the_lease(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeInspectionWorkOrderWithLeaseTenants();

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(2, $messages);
        $this->assertSame(['+17132526614', '+15712140948'], array_map(fn (Conversation $message) => $message->receiver_number, $messages));
        $this->assertStringContainsString('Hi Forrest, a work order #43900 has been created for 500 Elm St by our team.', $messages[0]->message);
        $this->assertStringContainsString('Hi Marisol, a work order #43900 has been created for 500 Elm St by our team.', $messages[1]->message);
        $this->assertNotNull($workOrder->fresh()->tenant_service_request_notified_at);

        Queue::assertPushed(SendConversationMessageJob::class, 2);

        // Each text is on the ledger, marked as sent to the lease roster
        // rather than to the requester.
        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'tenant_service_request_sms')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $ledger);
        $this->assertSame(['+17132526614', '+15712140948'], $ledger->pluck('properties.recipient')->all());
        $this->assertSame(['lease_roster', 'lease_roster'], $ledger->pluck('properties.recipient_source')->all());
        $this->assertSame(['staff_created', 'staff_created'], $ledger->pluck('properties.variant')->all());
    }

    public function test_a_requester_off_the_lease_is_passed_over_even_when_they_have_a_phone(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // A technician with a number on their PropertyWare contact must not
        // be told a work order was created for "your home".
        $workOrder = $this->makeInspectionWorkOrderWithLeaseTenants(technicianPhone: '8325550000');

        $this->notify($workOrder);

        $receivers = array_map(fn (Conversation $message) => $message->receiver_number, $this->tenantMessages($workOrder));

        $this->assertSame(['+17132526614', '+15712140948'], $receivers);
        $this->assertNotContains('+18325550000', $receivers);
    }

    public function test_a_requester_on_the_lease_is_texted_alone(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // A portal request is answered to the person who sent it, not to the
        // whole household.
        $workOrder = $this->makeWorkOrder(['source' => 'Tenant Portal']);
        $workOrder->tenants()->attach($workOrder->tenant_id);
        $this->makeContact('Marisol', '5712140948', $workOrder);

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(1, $messages);
        $this->assertSame('+15125559999', $messages[0]->receiver_number);
        $this->assertStringContainsString('Hi Dana,', $messages[0]->message);

        $ledger = Activity::query()->where('event', 'tenant_service_request_sms')->firstOrFail();
        $this->assertArrayNotHasKey('recipient_source', $ledger->properties->all());
    }

    public function test_a_requester_matched_by_propertyware_id_counts_as_on_the_lease(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // The same PropertyWare contact can sit in tenants twice (one row from
        // requestedByContact, one from the lease roster): still the requester.
        $requester = $this->makeContact('Dana', '5125559999', propertywareId: '7641137158');
        $workOrder = $this->makeWorkOrder(['tenant_id' => $requester->id]);
        $this->makeContact('Dana', '5125559999', $workOrder, propertywareId: '7641137158');
        $this->makeContact('Marisol', '5712140948', $workOrder);

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(1, $messages);
        $this->assertSame('+15125559999', $messages[0]->receiver_number);
    }

    public function test_the_other_lease_tenants_stand_in_for_a_requester_with_no_phone(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $requester = $this->makeContact('Dana', null);
        $workOrder = $this->makeWorkOrder(['tenant_id' => $requester->id]);
        $workOrder->tenants()->attach($requester->id);
        $this->makeContact('Marisol', '5712140948', $workOrder);

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(1, $messages);
        $this->assertSame('+15712140948', $messages[0]->receiver_number);
        $this->assertStringContainsString('Hi Marisol,', $messages[0]->message);
    }

    public function test_lease_tenants_sharing_one_number_get_a_single_text(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $technician = $this->makeContact('Moses', null);
        $workOrder = $this->makeStaffCreatedWorkOrder(['source' => 'Inspection', 'tenant_id' => $technician->id]);
        $this->makeContact('Forrest', '(713) 252-6614', $workOrder);
        $this->makeContact('Marisol', '7132526614', $workOrder);

        $this->notify($workOrder);

        $this->assertCount(1, $this->tenantMessages($workOrder));
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_a_lease_tenant_with_only_a_home_phone_is_still_texted(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // PropertyWare had both of WO#44111's tenants under Home Phone only.
        $technician = $this->makeContact('Moses', null);
        $workOrder = $this->makeStaffCreatedWorkOrder(['source' => 'Inspection', 'tenant_id' => $technician->id]);
        $homeOnly = $this->makeContact('Forrest', null, $workOrder);
        $homeOnly->update(['home_phone' => '(713) 252-6614']);

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(1, $messages);
        $this->assertSame('+17132526614', $messages[0]->receiver_number);
    }

    public function test_a_lease_less_work_order_still_texts_the_requester(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // A local row with no roster (a website request, an app-created work
        // order): the requester, exactly as before.
        $workOrder = $this->makeWorkOrder(['propertyware_id' => null]);

        $this->notify($workOrder);

        $messages = $this->tenantMessages($workOrder);

        $this->assertCount(1, $messages);
        $this->assertSame('+15125559999', $messages[0]->receiver_number);
    }

    public function test_the_skip_is_recorded_when_nobody_has_a_phone(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $technician = $this->makeContact('Moses', null);
        $workOrder = $this->makeStaffCreatedWorkOrder(['source' => 'Inspection', 'tenant_id' => $technician->id]);
        $this->makeContact('Forrest', null, $workOrder);

        $this->notify($workOrder);

        $this->assertSame([], $this->tenantMessages($workOrder));
        // Left un-stamped so a number added later can still be notified.
        $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at);
        Queue::assertNothingPushed();

        // The Automated Messages page shows why instead of nothing at all.
        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'tenant_service_request_sms')
            ->firstOrFail();
        $this->assertSame($workOrder->id, $ledger->properties['work_order_id']);
        $this->assertNull($ledger->properties['recipient']);
        $this->assertSame('no_tenant_phone', $ledger->properties['not_texted_reason']);
        $this->assertSame(
            'Not sent: nobody to text. Requested By is Moses Contact, who is not on the lease; 1 lease tenant(s) on file, none with a phone number.',
            $ledger->properties['message'],
        );
    }

    public function test_the_skip_names_a_missing_requester_and_an_empty_roster(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeWorkOrder(['tenant_id' => null, 'propertyware_id' => null]);

        $this->notify($workOrder);

        $this->assertSame([], $this->tenantMessages($workOrder));

        $ledger = Activity::query()->where('event', 'tenant_service_request_sms')->firstOrFail();
        $this->assertSame(
            'Not sent: nobody to text. PropertyWare lists no Requested By contact; no lease tenants on file.',
            $ledger->properties['message'],
        );
    }

    public function test_the_skip_names_a_requester_on_the_lease_with_no_phone(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $requester = $this->makeContact('Dana', null);
        $workOrder = $this->makeWorkOrder(['tenant_id' => $requester->id]);
        $workOrder->tenants()->attach($requester->id);

        $this->notify($workOrder);

        $ledger = Activity::query()->where('event', 'tenant_service_request_sms')->firstOrFail();
        $this->assertStringContainsString('Requested By is Dana Contact, who has no phone number', $ledger->properties['message']);
    }

    public function test_the_lease_roster_never_revives_a_vacant_or_opted_out_work_order(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        // WO#43729's lesson must survive the fallback: a home that is vacant,
        // being turned over, re-keyed or cleaned gets nothing, however many
        // reachable tenants sit on the roster PropertyWare attached.
        foreach ([
            ['type' => 'Turnover'],
            ['category' => 'Turnover'],
            ['category' => 'Re-key'],
            ['category' => 'Cleaning'],
            ['category' => 'Make ready'],
            ['skip_automated_tasks' => true],
            ['category' => WorkOrder::HOA_VIOLATION_CATEGORY],
        ] as $attributes) {
            $technician = $this->makeContact('Moses', null);
            $workOrder = $this->makeStaffCreatedWorkOrder(['source' => 'Inspection', 'tenant_id' => $technician->id] + $attributes);
            $this->makeContact('Forrest', '7132526614', $workOrder);
            $this->makeContact('Marisol', '5712140948', $workOrder);

            $this->notify($workOrder);

            $label = json_encode($attributes);
            $this->assertSame([], $this->tenantMessages($workOrder), "{$label} must send nothing.");
            $this->assertNull($workOrder->fresh()->tenant_service_request_notified_at, "{$label} must stay un-stamped.");
        }

        Queue::assertNothingPushed();
        // Opted-out work orders are not "nobody to text" either: no ledger row.
        $this->assertSame(0, Activity::query()->where('event', 'tenant_service_request_sms')->count());
    }

    public function test_the_per_work_order_mute_still_covers_the_lease_roster(): void
    {
        config(['services.twilio.tenant_intake_sms' => true]);
        Queue::fake();

        $workOrder = $this->makeInspectionWorkOrderWithLeaseTenants();
        $workOrder->setAutomationPaused('tenant', true);

        $this->notify($workOrder->fresh());

        $this->assertSame([], $this->tenantMessages($workOrder));
        Queue::assertNothingPushed();
    }

    // --- Tenant easy fix ---

    /**
     * Switch the easy-fix texts on, give the disposal item a video, and stub
     * PropertyWare's status push so the status move can be asserted.
     */
    private function enableEasyFix(bool $propertyWareAccepts = true): void
    {
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

        // The factory takes the first status on file; keep the easy-fix
        // statuses from being it so the status move is a real move.
        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
        ServiceStatus::query()->firstOrCreate(['name' => TenantEasyFixService::EASY_FIX_STATUS], ['description' => 'easy fix']);
        ServiceStatus::query()->firstOrCreate(['name' => TenantEasyFixService::APPLIANCE_STATUS], ['description' => 'non real property']);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('updateServiceStatus')->andReturn($propertyWareAccepts);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    /**
     * A tenant-portal request about a jammed disposal on a PropertyWare work
     * order with a lease.
     */
    private function makeDisposalWorkOrder(array $attributes = []): WorkOrder
    {
        return $this->makeWorkOrder(array_merge([
            'source' => 'Tenant Portal',
            'propertyware_id' => 43900001,
            'lease_id' => 555001,
            'description' => 'Garbage disposal is humming but not turning',
            'category' => 'Garbage Disposal',
        ], $attributes));
    }

    public function test_a_disposal_request_gets_the_how_to_video_instead_of_request_received(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder();

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('Hi Dana,', $text);
        $this->assertStringContainsString('We received your request about the garbage disposal.', $text);
        $this->assertStringContainsString('quick fix tenants can take care of themselves', $text);
        $this->assertStringContainsString('https://youtu.be/disposal', $text);
        $this->assertStringContainsString('press the red reset button', $text);
        $this->assertStringContainsString('If it still is not working after you try this', $text);
        $this->assertStringContainsString(TenantMessageFormatter::LINK_LEAD, $text);
        $this->assertStringContainsString('(Ref: WO#43900)', $text);
        $this->assertStringNotContainsString('we have received your service request', $text);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));

        // Links the easy-fix photo token, opened as already notified once so
        // the scheduled reminders follow from this text.
        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->firstOrFail();
        $this->assertStringContainsString(route('tenant.portal.show', $token->token), $text);
        $this->assertSame(1, $token->notified_count);

        Queue::assertPushed(SendConversationMessageJob::class, 1);

        $fresh = $workOrder->fresh();
        $this->assertNotNull($fresh->tenant_service_request_notified_at);
        $this->assertSame('disposal_jammed', $fresh->easy_fix_key);
        $this->assertSame(
            ServiceStatus::query()->where('name', TenantEasyFixService::EASY_FIX_STATUS)->value('id'),
            $fresh->service_status_id,
        );

        $ledger = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'tenant_easy_fix_sms')
            ->firstOrFail();
        $this->assertSame('disposal_jammed', $ledger->properties['easy_fix_key']);
        $this->assertSame(0, Activity::query()->where('event', 'tenant_service_request_sms')->count());
    }

    public function test_the_easy_fix_text_is_sent_once_and_the_portal_link_command_does_not_repeat_it(): void
    {
        $this->enableEasyFix();
        config(['services.twilio.tenant_portal_sms' => true, 'services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder();

        $this->notify($workOrder);
        $this->notify($workOrder->fresh());
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertCount(1, $this->tenantMessages($workOrder));
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_the_gate_off_keeps_the_request_received_text_but_still_records_the_verdict(): void
    {
        $this->enableEasyFix();
        config(['services.twilio.tenant_easy_fix_sms' => false]);
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder();

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('we have received your service request', $text);
        $this->assertStringNotContainsString('youtu.be', $text);

        $fresh = $workOrder->fresh();
        $this->assertSame('disposal_jammed', $fresh->easy_fix_key);
        $this->assertNotNull($fresh->easy_fix_assessed_at);
        $this->assertNull(TenantUploadToken::query()->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)->first());
        $this->assertNotSame(
            ServiceStatus::query()->where('name', TenantEasyFixService::EASY_FIX_STATUS)->value('id'),
            $fresh->service_status_id,
        );
    }

    public function test_an_item_with_no_video_yet_keeps_the_request_received_text(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        // The light-bulb item has no handbook link filled in.
        $workOrder = $this->makeDisposalWorkOrder(['description' => 'Light bulb in the hallway burned out', 'category' => 'Light Fixture']);

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('we have received your service request', $text);
        $this->assertSame('light_bulb', $workOrder->fresh()->easy_fix_key);
    }

    public function test_a_leaking_disposal_keeps_the_request_received_text(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder(['description' => 'Garbage disposal leaking under the sink']);

        $this->notify($workOrder);

        $this->assertStringContainsString('we have received your service request', $this->tenantMessage($workOrder)->message);
        $this->assertNull($workOrder->fresh()->easy_fix_key);
    }

    public function test_a_disposal_work_order_our_team_entered_keeps_the_created_by_our_team_text(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder(['source' => 'Phone']);

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('by our team', $text);
        $this->assertStringNotContainsString('youtu.be', $text);
    }

    public function test_a_tenant_owned_washer_is_told_it_is_their_responsibility(): void
    {
        $this->enableEasyFix();
        Queue::fake();

        $building = Building::query()->create([
            'propertyware_id' => 'B-700OAK',
            'name' => 'Oak',
            'address' => '700 Oak St',
            'city' => 'Houston',
            'state_region' => 'TX',
            'custom_fields' => [['fieldName' => 'Included Appliances', 'value' => 'refrigerator', 'dataType' => 'Text']],
        ]);
        $workOrder = $this->makeDisposalWorkOrder([
            'description' => 'Our washing machine will not spin',
            'category' => 'Washer',
            'building_id' => $building->propertyware_id,
        ]);

        $this->notify($workOrder);

        $text = $this->tenantMessage($workOrder)->message;
        $this->assertStringContainsString('We received your request about the washer.', $text);
        $this->assertStringContainsString("the tenant's responsibility under the lease", $text);
        $this->assertStringContainsString('reply here and we will double-check', $text);
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));

        // General portal link, no easy-fix photo token.
        $general = TenantUploadToken::query()->where('work_order_id', $workOrder->id)->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)->firstOrFail();
        $this->assertStringContainsString(route('tenant.portal.show', $general->token), $text);
        $this->assertSame(0, TenantUploadToken::query()->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)->count());

        $fresh = $workOrder->fresh();
        $this->assertSame('appliance_washer', $fresh->easy_fix_key);
        $this->assertSame(
            ServiceStatus::query()->where('name', TenantEasyFixService::APPLIANCE_STATUS)->value('id'),
            $fresh->service_status_id,
        );

        $this->assertSame(1, Activity::query()->where('event', 'tenant_appliance_responsibility_sms')->count());
    }

    public function test_the_status_stays_put_when_propertyware_rejects_it_but_the_text_still_goes(): void
    {
        $this->enableEasyFix(propertyWareAccepts: false);
        Queue::fake();

        $workOrder = $this->makeDisposalWorkOrder();
        $before = $workOrder->service_status_id;

        $this->notify($workOrder);

        $this->assertStringContainsString('https://youtu.be/disposal', $this->tenantMessage($workOrder)->message);
        $this->assertSame($before, $workOrder->fresh()->service_status_id);
    }
}
