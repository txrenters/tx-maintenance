<?php

namespace Tests\Feature;

use App\Console\Commands\FollowUpTenantVendorContact;
use App\Jobs\SendConversationMessageJob;
use App\Models\ServiceSchedule;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantVendorContactFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate starts off; a deterministic "from" number so the send fires.
        config([
            'services.twilio.tenant_vendor_followup_sms' => false,
            'services.twilio.maintenance_number' => '+12813787957',
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

    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function openWorkOrder(?Tenants $tenant = null, string $status = 'Open'): WorkOrder
    {
        return WorkOrder::factory()->create([
            'status' => $status,
            'work_order_no' => 5100 + random_int(1, 800),
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ]);
    }

    /**
     * Attach the vendor and back-date the assignment so the "day after
     * assignment" age gate is (or is not) satisfied deterministically.
     */
    private function assignVendor(WorkOrder $workOrder, Vendor $vendor, int $assignedDaysAgo = 1): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);

        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update(['created_at' => now()->subDays($assignedDaysAgo)]);
    }

    private function followupCount(WorkOrder $workOrder): int
    {
        return (int) DB::table('work_orders')->where('id', $workOrder->id)->value('tenant_contact_followup_count');
    }

    private function lastSentAt(WorkOrder $workOrder): ?string
    {
        return DB::table('work_orders')->where('id', $workOrder->id)->value('tenant_contact_followup_last_sent_at');
    }

    private function excludedAt(WorkOrder $workOrder): ?string
    {
        return DB::table('work_orders')->where('id', $workOrder->id)->value('tenant_contact_followup_excluded_at');
    }

    public function test_it_texts_the_tenant_the_day_after_assignment_with_no_schedule(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertSame(1, $this->followupCount($workOrder));
        $this->assertNotNull($this->lastSentAt($workOrder));
        $this->assertNull($this->excludedAt($workOrder), 'A normal work order is never excluded.');
    }

    public function test_it_does_not_text_on_the_same_day_as_assignment(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        // Assigned just now — the one-day wait has not elapsed.
        $this->assignVendor($workOrder, $this->makeVendor(), assignedDaysAgo: 0);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->followupCount($workOrder));
    }

    public function test_it_does_not_text_when_the_gate_is_off(): void
    {
        // Gate defaults off (see setUp).
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->followupCount($workOrder));
    }

    public function test_it_does_not_text_when_a_service_schedule_exists(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $vendor = $this->makeVendor();
        $this->assignVendor($workOrder, $vendor);

        ServiceSchedule::query()->create([
            'title' => 'Tenant visit',
            'scheduled_date' => now()->addDay(),
            'status' => 'scheduled',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->followupCount($workOrder));
    }

    public function test_it_does_not_text_a_closed_work_order(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder(status: 'Closed');
        $this->assignVendor($workOrder, $this->makeVendor());

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_nag_the_pre_existing_backlog(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        // Simulate the fresh-start backfill: mark this as already excluded.
        DB::table('work_orders')->where('id', $workOrder->id)
            ->update(['tenant_contact_followup_excluded_at' => now()]);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->followupCount($workOrder));
    }

    public function test_it_respects_the_tenant_automation_mute(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());
        $workOrder->setAutomationPaused('tenant', true);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        // Paused before claiming, so no day is consumed — it resumes if unmuted.
        $this->assertSame(0, $this->followupCount($workOrder));
    }

    public function test_it_skips_a_turnover_work_order(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());
        $workOrder->update(['type' => 'Turnover']);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        // Skipped before claiming, not excluded, so the nudge resumes if the
        // type is corrected and the unit is occupied after all.
        $this->assertSame(0, $this->followupCount($workOrder));
        $this->assertNull($this->excludedAt($workOrder));
    }

    public function test_it_skips_a_work_order_marked_vacant(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());
        $workOrder->update(['skip_automated_tasks' => true]);

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->followupCount($workOrder));
        $this->assertNull($this->excludedAt($workOrder));
    }

    public function test_it_never_texts_for_the_owner_vendor_placeholder(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        // "OWNER VENDOR" = the owner handles the repair themselves; there is no
        // vendor to have reached out, so exclude it so it is never rescanned.
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor('OWNER VENDOR'));

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNotNull($this->excludedAt($workOrder), 'The owner-handled work order should be excluded.');
    }

    public function test_it_excludes_thmp_from_the_followup(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        // THMP (in-house) does not reach out to schedule the way a third-party
        // vendor does — it messages the tenant manually — so a THMP-only work
        // order is excluded and never asked "has the vendor reached out?".
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor('Texas Home Maintenance Pros'));

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNotNull($this->excludedAt($workOrder), 'THMP work orders are excluded from the tenant follow-up.');
    }

    public function test_it_still_texts_when_thmp_and_a_third_party_are_both_assigned(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        // A third-party vendor alongside THMP still reaches out to schedule, so
        // the follow-up should fire for it.
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor('Texas Home Maintenance Pros'));
        $this->assignVendor($workOrder, $this->makeVendor('Reliable Plumbing'));

        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertNull($this->excludedAt($workOrder), 'A work order with a real third-party vendor is not excluded.');
    }

    public function test_it_texts_at_most_once_per_day(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        // Two runs on the same day should still only text once.
        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);
        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 1);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertSame(1, $this->followupCount($workOrder));
    }

    public function test_it_texts_again_the_next_day_until_scheduled(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        // Day 1: one nudge.
        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);
        Queue::assertPushed(SendConversationMessageJob::class, 1);

        // Day 2: still no schedule, so it nudges again.
        $this->travel(1)->days();
        $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 2);
        Queue::assertPushed(SendConversationMessageJob::class, 2);
        $this->assertSame(2, $this->followupCount($workOrder));
    }

    public function test_it_stops_after_the_cap_is_reached(): void
    {
        config(['services.twilio.tenant_vendor_followup_sms' => true]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $this->makeVendor());

        // Run one nudge per day for more days than the cap allows.
        for ($day = 0; $day < FollowUpTenantVendorContact::MAX_NOTIFICATIONS + 2; $day++) {
            $this->artisan('tenants:followup-vendor-contact')->assertExitCode(0);
            $this->travel(1)->days();
        }

        Queue::assertPushed(SendConversationMessageJob::class, FollowUpTenantVendorContact::MAX_NOTIFICATIONS);
        $this->assertSame(FollowUpTenantVendorContact::MAX_NOTIFICATIONS, $this->followupCount($workOrder));
    }
}
