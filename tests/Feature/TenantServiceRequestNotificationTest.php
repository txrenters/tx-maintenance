<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\TenantMessageFormatter;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
