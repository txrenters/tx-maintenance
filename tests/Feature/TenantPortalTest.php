<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\UploadAttachment;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantPortalTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 'tenant@example.com',
            'mobile_phone' => '5125559999',
            'address' => '6341 Del Monte Dr',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function easyFixStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'The service request is checking for an easy fix.'],
        );
    }

    private function makeWorkOrder(?Tenants $tenant = null, ?ServiceStatus $status = null): WorkOrder
    {
        $status ??= $this->easyFixStatus();

        return WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43361,
            'description' => 'There is water dripping from the roof down into the backyard',
            'tenant_id' => $tenant?->id,
        ]);
    }

    private function makeToken(WorkOrder $workOrder): TenantUploadToken
    {
        return TenantUploadToken::create([
            'token' => 'demo-tenant-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
        ]);
    }

    public function test_a_bad_token_404s(): void
    {
        $this->get('/tenant-portal/not-a-real-token')->assertNotFound();
    }

    public function test_a_valid_token_renders_the_portal(): void
    {
        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TenantPortal/Show')
                ->where('workOrder.work_order_no', 43361)
                ->where('workOrder.address', '6341 Del Monte Dr')
                ->where('tenantName', 'Dana')
                ->where('completed', false));
    }

    public function test_a_tenant_can_upload_photos_which_completes_the_request(): void
    {
        Queue::fake();
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.attachments', $token->token), [
            'files' => [
                UploadedFile::fake()->image('leak-1.jpg'),
                UploadedFile::fake()->image('leak-2.jpg'),
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('attachments', 2);
        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'uploaded_via_tenant_portal' => true,
            'type' => 'before',
        ]);

        Queue::assertPushed(UploadAttachment::class, 2);

        // Photos received stops the reminders.
        $this->assertNotNull($token->fresh()->completed_at);
    }

    public function test_rejects_non_photo_files(): void
    {
        Storage::fake('public');

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.attachments', $token->token), [
            'files' => [UploadedFile::fake()->create('malware.exe', 100)],
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_the_tenant_can_mark_themselves_done(): void
    {
        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);

        $this->post(route('tenant.portal.complete', $token->token))->assertRedirect();

        $this->assertNotNull($token->fresh()->completed_at);
    }

    public function test_the_command_sends_the_link_for_easy_fix_work_orders(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $tenant = $this->makeTenant();
        $workOrder = $this->makeWorkOrder($tenant);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $token = TenantUploadToken::query()->where('work_order_id', $workOrder->id)->first();
        $this->assertNotNull($token);
        $this->assertSame(1, $token->notified_count);

        $message = $workOrder->tenant_conversation()->firstOrFail();
        $this->assertStringContainsString('upload photos', $message->message);
        $this->assertStringContainsString($token->token, $message->message);
        $this->assertStringContainsString('(Ref: WO#43361)', $message->message);
        $this->assertSame('+15125559999', $message->receiver_number);

        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_the_command_is_silent_when_the_gate_is_off(): void
    {
        // Gate defaults to off.
        Queue::fake();

        $this->makeWorkOrder($this->makeTenant());

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertDatabaseCount('tenant_upload_tokens', 0);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_the_link_is_sent_only_once_per_work_order(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());

        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $workOrder->id)->count());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_a_reminder_goes_out_after_two_weekdays_and_is_capped(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);
        $token->update(['notified_count' => 1, 'last_notified_at' => now()->subWeekdays(3)]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(2, $token->fresh()->notified_count);
        Queue::assertPushed(SendConversationMessageJob::class, 1);

        // At the cap, no further reminders even when stale again.
        $token->update(['notified_count' => 3, 'last_notified_at' => now()->subWeekdays(5)]);
        $this->artisan('tenant-portal:send-links')->assertSuccessful();
        $this->assertSame(3, $token->fresh()->notified_count);
    }

    public function test_a_completed_request_gets_no_reminder(): void
    {
        config(['services.twilio.tenant_portal_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        $workOrder = $this->makeWorkOrder($this->makeTenant());
        $token = $this->makeToken($workOrder);
        $token->update([
            'notified_count' => 1,
            'last_notified_at' => now()->subWeekdays(3),
            'completed_at' => now()->subDay(),
        ]);

        $this->artisan('tenant-portal:send-links')->assertSuccessful();

        $this->assertSame(1, $token->fresh()->notified_count);
        Queue::assertNotPushed(SendConversationMessageJob::class);
    }
}
