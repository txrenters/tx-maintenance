<?php

namespace Tests\Feature;

use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use App\Services\TenantWorkOrderEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TenantWorkOrderIntakeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.work_order.tenant_intake_email' => false]);
    }

    /**
     * Swap the Graph mailer for a spy so nothing leaves the machine, and hand
     * back the captured send arguments.
     */
    private function fakeGraph(bool $expectSend = true): object
    {
        $captured = new class
        {
            public bool $sent = false;

            public string $to = '';

            public string $subject = '';

            public string $html = '';
        };

        $mock = Mockery::mock(MicrosoftGraphMailService::class);

        if ($expectSend) {
            $mock->shouldReceive('sendMail')
                ->once()
                ->andReturnUsing(function ($to, $cc, $subject, $html) use ($captured) {
                    $captured->sent = true;
                    $captured->to = $to;
                    $captured->subject = $subject;
                    $captured->html = $html;

                    return [
                        'graph_message_id' => 'msg-1',
                        'graph_conversation_id' => 'conv-1',
                        'internet_message_id' => '<msg-1@example.com>',
                    ];
                });
        } else {
            $mock->shouldReceive('sendMail')->never();
        }

        $this->app->instance(MicrosoftGraphMailService::class, $mock);

        return $captured;
    }

    private function makeWorkOrder(?string $email = 'dana@example.com'): WorkOrder
    {
        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => $email,
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);

        return WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43900,
            'description' => 'Water heater is leaking in the garage',
            'tenant_id' => $tenant->id,
        ]);
    }

    private function send(WorkOrder $workOrder): bool
    {
        return app(TenantWorkOrderEmailSender::class)->sendIntakeConfirmation($workOrder);
    }

    public function test_it_emails_the_tenant_with_the_portal_link(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);
        $captured = $this->fakeGraph();

        $workOrder = $this->makeWorkOrder();

        $this->assertTrue($this->send($workOrder));

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_WORK_ORDER)
            ->firstOrFail();

        $this->assertTrue($captured->sent);
        $this->assertSame('dana@example.com', $captured->to);
        $this->assertStringContainsString('We received your service request', $captured->subject);
        $this->assertStringContainsString('Work Order #43900', $captured->subject);

        // The branded shell, the button, and the link itself.
        $this->assertStringContainsString('View Work Order #43900', $captured->html);
        $this->assertStringContainsString($token->token, $captured->html);
        $this->assertStringContainsString('Water heater is leaking in the garage', $captured->html);

        // Recorded for reply threading.
        $this->assertDatabaseHas('tenant_email_notifications', [
            'to_email' => 'dana@example.com',
            'direction' => 'outbound',
        ]);
    }

    public function test_it_is_silent_when_the_gate_is_off(): void
    {
        $this->fakeGraph(expectSend: false);

        $this->assertFalse($this->send($this->makeWorkOrder()));

        $this->assertDatabaseCount('tenant_email_notifications', 0);
    }

    public function test_it_skips_the_synthetic_importer_placeholder_address(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);
        $this->fakeGraph(expectSend: false);

        // What PropertyWare writes when a tenant has no address on file.
        $this->assertFalse($this->send($this->makeWorkOrder('t-1234@texasrenter.com')));
    }

    public function test_it_skips_a_blank_or_invalid_address(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);
        $this->fakeGraph(expectSend: false);

        $this->assertFalse($this->send($this->makeWorkOrder('')));
        $this->assertFalse($this->send($this->makeWorkOrder('not-an-email')));
    }

    public function test_it_respects_the_tenant_automation_mute(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);
        $this->fakeGraph(expectSend: false);

        $workOrder = $this->makeWorkOrder();
        $workOrder->setAutomationPaused('tenant', true);

        $this->assertFalse($this->send($workOrder->fresh()));
    }

    public function test_a_send_failure_never_breaks_intake(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);

        $mock = Mockery::mock(MicrosoftGraphMailService::class);
        $mock->shouldReceive('sendMail')->andThrow(new \RuntimeException('Graph is down'));
        $this->app->instance(MicrosoftGraphMailService::class, $mock);

        // Logged and swallowed, not thrown.
        $this->assertFalse($this->send($this->makeWorkOrder()));
    }
}
