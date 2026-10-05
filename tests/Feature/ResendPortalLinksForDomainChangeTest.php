<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The one-time notice that tells everyone on an open work order that the
 * portal links moved to the new domain, with their new link.
 */
class ResendPortalLinksForDomainChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://maintenance.texasrenters.com',
            'services.twilio.maintenance_number' => '+12813787957',
        ]);
        url()->forceRootUrl('https://maintenance.texasrenters.com');
        url()->forceScheme('https');

        Queue::fake();
    }

    private function makeWorkOrder(string $status = 'Open', int $number = 51001): WorkOrder
    {
        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'status' => $status,
            'work_order_no' => $number,
            'tenant_id' => $tenant->id,
        ]);

        $owner = Owner::query()->create([
            'first_name' => 'Olivia',
            'last_name' => 'Owner',
            'name' => 'Olivia Owner',
            'email' => 'o'.uniqid().'@example.com',
            'mobile' => '7135030427',
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->owners()->attach($owner->id);

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => User::factory()->create(['phone' => '2815550000'])->id,
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-vendor-'.$number]);

        return $workOrder;
    }

    /**
     * @return array<string, string>
     */
    private function messagesByAudience(WorkOrder $workOrder): array
    {
        return Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->pluck('message', 'conversation_type')
            ->all();
    }

    public function test_dry_run_sends_nothing(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->artisan('portal-links:resend-for-domain-change')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame([], $this->messagesByAudience($workOrder));
        Queue::assertNothingPushed();
    }

    public function test_send_texts_tenant_owner_and_vendor_their_new_link(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->artisan('portal-links:resend-for-domain-change --send')->assertSuccessful();

        $messages = $this->messagesByAudience($workOrder);

        $this->assertCount(3, $messages);
        $this->assertStringContainsString('https://maintenance.texasrenters.com/tenant-portal/', $messages['tenant']);
        $this->assertStringContainsString('https://maintenance.texasrenters.com/owner-portal/', $messages['owner']);
        $this->assertStringContainsString('https://maintenance.texasrenters.com/vendor-portal/tok-vendor-51001', $messages['vendor']);

        foreach ($messages as $message) {
            $this->assertStringContainsString('updating our domain', $message);
            $this->assertStringContainsString('#51001', $message);
        }

        Queue::assertPushed(SendConversationMessageJob::class, 3);
    }

    public function test_closed_work_orders_are_skipped(): void
    {
        $workOrder = $this->makeWorkOrder('Closed', 51002);

        $this->artisan('portal-links:resend-for-domain-change --send')->assertSuccessful();

        $this->assertSame([], $this->messagesByAudience($workOrder));
        Queue::assertNothingPushed();
    }

    public function test_running_twice_never_texts_anyone_twice(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->artisan('portal-links:resend-for-domain-change --send')->assertSuccessful();
        $this->artisan('portal-links:resend-for-domain-change --send')->assertSuccessful();

        $this->assertSame(3, Conversation::query()->where('work_order_id', $workOrder->id)->count());
        $this->assertSame(3, Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('event', 'portal_domain_change_sms')
            ->count());
    }

    public function test_paused_automation_is_respected(): void
    {
        $workOrder = $this->makeWorkOrder();
        $workOrder->setAutomationPaused('vendor', true);

        $this->artisan('portal-links:resend-for-domain-change --send')->assertSuccessful();

        $this->assertArrayNotHasKey('vendor', $this->messagesByAudience($workOrder->fresh()));
    }

    public function test_refuses_to_send_links_on_a_non_https_domain(): void
    {
        config(['app.url' => 'http://localhost:8080']);
        url()->forceRootUrl('http://localhost:8080');
        url()->forceScheme('http');
        $this->makeWorkOrder();

        $this->artisan('portal-links:resend-for-domain-change --send')->assertFailed();

        Queue::assertNothingPushed();
    }
}
