<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Attachments;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\HoaViolationConfirmationSender;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class HoaViolationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function easyFixStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'x'],
        );
    }

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

    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Repairs',
            'vendor_type' => 'General',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function hoaWorkOrder(?Tenants $tenant = null): WorkOrder
    {
        return WorkOrder::factory()->create([
            'service_status_id' => $this->easyFixStatus()->id,
            'work_order_no' => 60001,
            'category' => 'HOA Violation',
            'description' => 'Trim the front lawn.',
            'tenant_id' => $tenant?->id,
        ]);
    }

    private function hoaToken(WorkOrder $workOrder, array $overrides = []): TenantUploadToken
    {
        return TenantUploadToken::create(array_merge([
            'token' => 'hoa-token-'.$workOrder->id,
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'hoa_notice_date' => now()->toDateString(),
            'hoa_deadline_at' => now()->addWeekdays(5)->endOfDay(),
        ], $overrides));
    }

    public function test_the_portal_renders_the_hoa_template_with_the_deadline(): void
    {
        $workOrder = $this->hoaWorkOrder($this->tenant());
        $token = $this->hoaToken($workOrder, ['hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay()]);

        $this->get(route('tenant.portal.show', $token->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TenantPortal/Show')
                ->where('isHoa', true)
                ->where('deadline', 'Monday, July 27, 2026'));
    }

    public function test_a_daily_reminder_goes_out_before_the_deadline(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00')); // Tuesday

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $token = $this->hoaToken($workOrder, [
            'hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay(),
            'last_notified_at' => Carbon::parse('2026-07-20 10:00:00'),
        ]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        $this->assertTrue($token->fresh()->last_notified_at->isToday());
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $message = $workOrder->tenant_conversation()->firstOrFail();
        $this->assertStringContainsString('HOA', $message->message);
        $this->assertStringContainsString('TexasRenters.com Maintenance', $message->message);
    }

    public function test_no_reminder_once_the_tenant_has_uploaded_photos(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00')); // Tuesday, before the deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        // Deadline is still in the future, but the tenant already uploaded
        // proof (completed_at set) — reminders must stop.
        $this->hoaToken($workOrder, [
            'hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay(),
            'last_notified_at' => Carbon::parse('2026-07-20 10:00:00'),
            'completed_at' => Carbon::parse('2026-07-21 09:00:00'),
        ]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_no_reminder_once_the_woc_closes_the_work_order(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00')); // Tuesday, before the deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        // The WOC closed it (she closes once the tenant sends proof back), so
        // reminders must stop even though the deadline is still in the future.
        $workOrder->update(['status' => 'Closed']);
        $this->hoaToken($workOrder, [
            'hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay(),
            'last_notified_at' => Carbon::parse('2026-07-20 10:00:00'),
        ]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_a_closed_work_order_is_not_escalated_as_overdue(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-28 10:00:00')); // after the deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $workOrder->update(['status' => 'Closed']);
        $token = $this->hoaToken($workOrder, ['hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay()]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        // The WOC already handled it by closing — no overdue flag, no activity.
        $this->assertNull($token->fresh()->escalation_flagged_at);
        $this->assertDatabaseMissing('activity_log', [
            'event' => 'hoa_violation_overdue',
            'subject_id' => $workOrder->id,
        ]);
    }

    public function test_no_reminder_once_a_vendor_is_scheduled(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00')); // Tuesday, before the deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        // Tenant missed the self-fix window and staff scheduled a vendor, so the
        // tenant reminders must stop even though the deadline is still ahead.
        $vendor = $this->vendor();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok'.$vendor->id]);
        $this->hoaToken($workOrder, [
            'hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay(),
            'last_notified_at' => Carbon::parse('2026-07-20 10:00:00'),
        ]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_no_overdue_flag_once_a_vendor_is_scheduled(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-28 10:00:00')); // after the deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $vendor = $this->vendor();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok'.$vendor->id]);
        $token = $this->hoaToken($workOrder, ['hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay()]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        // A vendor is already on it — the escalation's goal is met, so no flag.
        $this->assertNull($token->fresh()->escalation_flagged_at);
    }

    public function test_reminders_stop_at_the_gate(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00'));

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $this->hoaToken($workOrder, ['last_notified_at' => Carbon::parse('2026-07-20 10:00:00')]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_no_reminder_once_the_deadline_has_passed(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-28 10:00:00')); // Tuesday, after deadline

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $this->hoaToken($workOrder, [
            'hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay(),
            'last_notified_at' => Carbon::parse('2026-07-24 10:00:00'),
        ]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        Queue::assertNotPushed(SendConversationMessageJob::class);
    }

    public function test_an_overdue_violation_is_flagged_for_staff_once(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-28 10:00:00'));

        $workOrder = $this->hoaWorkOrder($this->tenant());
        $token = $this->hoaToken($workOrder, ['hoa_deadline_at' => Carbon::parse('2026-07-27')->endOfDay()]);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        $this->assertNotNull($token->fresh()->escalation_flagged_at);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'hoa_violation_overdue',
            'subject_id' => $workOrder->id,
        ]);

        // Running again does not double-flag.
        $this->artisan('hoa:send-reminders')->assertSuccessful();
        $this->assertSame(1, Activity::query()
            ->where('event', 'hoa_violation_overdue')->count());
    }

    public function test_a_completed_violation_emails_the_tenant_and_owner_once(): void
    {
        config(['services.twilio.hoa_violation_sms' => true]);
        $this->travelTo(Carbon::parse('2026-07-25 10:00:00'));

        $tenant = $this->tenant();
        $workOrder = $this->hoaWorkOrder($tenant);
        $owner = Owner::factory()->create([
            'name' => 'Sam Owner',
            'email' => 'owner@example.com',
        ]);
        $workOrder->owners()->attach($owner->id);

        $token = $this->hoaToken($workOrder, ['completed_at' => now()]);

        // A proof photo exists.
        Attachments::query()->create([
            'title' => 'Tenant photo',
            'filename' => 'attachments/proof.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $tenant->user_id,
            'uploaded_via_tenant_portal' => true,
        ]);

        $captured = [];
        $sender = Mockery::mock(HoaViolationConfirmationSender::class);
        $sender->shouldReceive('send')->once()->andReturnUsing(function (TenantUploadToken $t) use (&$captured) {
            $captured[] = $t->id;

            return true;
        });
        $this->app->instance(HoaViolationConfirmationSender::class, $sender);

        $this->artisan('hoa:send-reminders')->assertSuccessful();

        $this->assertNotNull($token->fresh()->confirmation_sent_at);
        $this->assertCount(1, $captured);

        // Second run does not resend.
        $this->artisan('hoa:send-reminders')->assertSuccessful();
        $this->assertCount(1, $captured);
    }

    public function test_the_confirmation_sender_emails_tenant_and_owner_from_the_service_mailbox(): void
    {
        config(['services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com']);

        $tenant = $this->tenant();
        $workOrder = $this->hoaWorkOrder($tenant);
        $owner = Owner::factory()->create(['email' => 'owner@example.com']);
        $workOrder->owners()->attach($owner->id);
        $token = $this->hoaToken($workOrder, ['completed_at' => now()]);

        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('sendMail')
            ->once()
            ->withArgs(function ($to, $cc, $subject, $html, $attachments, $mailbox) {
                return $to === 'tenant@example.com'
                    && $cc === ['owner@example.com']
                    && str_contains($subject, 'HOA Violation Corrected')
                    && str_contains($html, 'hoa/photos')
                    && str_contains($html, 'signature=')
                    && $mailbox === 'service@txhomemp.com';
            })
            ->andReturn([
                'graph_message_id' => 'x',
                'internet_message_id' => null,
                'graph_conversation_id' => null,
            ]);

        $sender = new HoaViolationConfirmationSender($graph);
        $this->assertTrue($sender->send($token));
    }

    public function test_the_signed_photo_gallery_rejects_an_unsigned_url(): void
    {
        $workOrder = $this->hoaWorkOrder($this->tenant());

        // Unsigned request is rejected.
        $this->get('/hoa/photos/'.$workOrder->id)->assertForbidden();

        // A properly signed URL renders the gallery.
        $signed = URL::signedRoute('hoa.photos.show', ['workOrder' => $workOrder->id]);
        $this->get($signed)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('HoaPhotoGallery/Show'));
    }
}
