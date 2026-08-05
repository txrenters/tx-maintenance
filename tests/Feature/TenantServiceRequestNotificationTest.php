<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TenantServiceRequestNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
