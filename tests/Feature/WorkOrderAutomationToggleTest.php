<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TenantPortalLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkOrderAutomationToggleTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'address' => '123 Oak Ridge Dr',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_toggling_pauses_and_resumes_a_channel(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($user)
            ->patch(route('work_order.automation.toggle', $workOrder), ['channel' => 'tenant', 'paused' => true])
            ->assertRedirect();

        $this->assertTrue($workOrder->fresh()->automationPausedFor('tenant'));

        $this->actingAs($user)
            ->patch(route('work_order.automation.toggle', $workOrder), ['channel' => 'tenant', 'paused' => false])
            ->assertRedirect();

        $this->assertFalse($workOrder->fresh()->automationPausedFor('tenant'));
    }

    public function test_channels_are_independent(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($user)->patch(route('work_order.automation.toggle', $workOrder), ['channel' => 'owner', 'paused' => true]);
        $this->actingAs($user)->patch(route('work_order.automation.toggle', $workOrder), ['channel' => 'vendor', 'paused' => true]);

        $fresh = $workOrder->fresh();
        $this->assertTrue($fresh->automationPausedFor('owner'));
        $this->assertTrue($fresh->automationPausedFor('vendor'));
        $this->assertFalse($fresh->automationPausedFor('tenant'));
    }

    public function test_show_returns_the_paused_channels(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create(['paused_automations' => ['owner']]);

        $this->actingAs($user)
            ->getJson(route('work_order.automation.show', $workOrder))
            ->assertOk()
            ->assertExactJson(['paused_automations' => ['owner']]);
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $this->actingAs($user)
            ->patch(route('work_order.automation.toggle', $workOrder), ['channel' => 'staff', 'paused' => true])
            ->assertSessionHasErrors('channel');

        $this->assertSame([], $workOrder->fresh()->paused_automations ?? []);
    }

    public function test_a_paused_tenant_channel_silences_the_automated_link(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $tenant = $this->tenant();
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'paused_automations' => ['tenant'],
        ]);

        $token = TenantUploadToken::create([
            'token' => 'hoa-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'hoa_notice_date' => now()->toDateString(),
            'hoa_deadline_at' => now()->addWeekdays(5)->endOfDay(),
        ]);

        app(TenantPortalLinkService::class)->sendHoaLink($token);

        // Paused: nothing queued and no conversation entry written.
        Queue::assertNotPushed(SendConversationMessageJob::class);
        $this->assertSame(0, $workOrder->tenant_conversation()->count());
    }

    public function test_an_unpaused_tenant_channel_still_sends(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $tenant = $this->tenant();
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'paused_automations' => ['owner'], // a different channel is paused
        ]);

        $token = TenantUploadToken::create([
            'token' => 'hoa-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'hoa_notice_date' => now()->toDateString(),
            'hoa_deadline_at' => now()->addWeekdays(5)->endOfDay(),
        ]);

        app(TenantPortalLinkService::class)->sendHoaLink($token);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }
}
