<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\ServiceSchedule;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VendorScheduleFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate starts off; a deterministic "from" number so the send fires.
        config([
            'services.twilio.schedule_followup_sms' => false,
            'services.twilio.maintenance_number' => '+12813787957',
        ]);
    }

    private function makeVendor(?string $phone = '2815550000'): Vendor
    {
        $user = User::factory()->create();
        $user->forceFill(['phone' => $phone])->save();

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Assign the vendor to the work order. The daily follow-up no longer looks
     * at how long ago the assignment happened, so no back-dating is needed.
     */
    private function assignVendor(WorkOrder $workOrder, Vendor $vendor): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);
    }

    private function openWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 5100 + random_int(1, 800)]);
    }

    private function followupSentAt(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        return DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->value('schedule_followup_sent_at');
    }

    private function lastSentAt(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        return DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->value('schedule_followup_last_sent_at');
    }

    public function test_it_texts_a_vendor_right_after_assignment_with_no_schedule(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        // The daily throttle is stamped; the exclusion baseline stays clear.
        $this->assertNotNull($this->lastSentAt($workOrder, $vendor), 'Today\'s nudge should be stamped.');
        $this->assertNull($this->followupSentAt($workOrder, $vendor), 'A normal assignment is never excluded.');
    }

    public function test_it_does_not_text_when_the_gate_is_off(): void
    {
        // Gate defaults off (see setUp).
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNull($this->lastSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_text_a_work_order_that_already_has_a_schedule(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        ServiceSchedule::query()->create([
            'title' => 'Tenant visit',
            'scheduled_date' => now()->addDay(),
            'status' => 'scheduled',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNull($this->lastSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_nag_the_pre_existing_backlog(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        // Simulate the fresh-start backfill: mark this as already handled.
        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update(['schedule_followup_sent_at' => now()]);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNull($this->lastSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_text_a_closed_work_order(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'work_order_no' => 6001]);
        $this->assignVendor($workOrder, $vendor);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_never_nags_the_owner_vendor_placeholder(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        // "OWNER VENDOR" = the owner handles the repair themselves. They have no
        // vendor dashboard, so no text AND no conversation-thread nag — and the
        // assignment is stamped on the exclusion baseline so it is never rescanned.
        $vendor = $this->makeVendor(phone: null);
        $vendor->update(['name' => 'OWNER VENDOR']);

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNotNull($this->followupSentAt($workOrder, $vendor), 'The placeholder assignment should be excluded so it is never rescanned.');
    }

    public function test_it_texts_at_most_once_per_day(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        // Two runs on the same day should still only text once.
        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);
        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 1);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_it_texts_again_the_next_day_until_scheduled(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        // Day 1: one nudge.
        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);
        Queue::assertPushed(SendConversationMessageJob::class, 1);

        // Day 2: still no schedule, so it nudges again.
        $this->travel(1)->days();
        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 2);
        Queue::assertPushed(SendConversationMessageJob::class, 2);
    }

    /**
     * @return array<int, string> the nudge texts sent so far, oldest first
     */
    private function sentMessages(WorkOrder $workOrder, Vendor $vendor): array
    {
        return DB::table('work_order_conversations')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->orderBy('id')
            ->pluck('message')
            ->all();
    }

    public function test_repeat_nudges_use_different_wording_each_day(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        foreach (range(1, 3) as $day) {
            $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);
            $this->travel(1)->days();
        }

        $messages = $this->sentMessages($workOrder, $vendor);

        $this->assertCount(3, $messages);
        // Day 1 is the operations-approved first notice.
        $this->assertStringContainsString('a service schedule has not yet been set', $messages[0]);
        // Repeats rephrase instead of resending the same canned text.
        $this->assertNotSame($messages[0], $messages[1]);
        $this->assertNotSame($messages[1], $messages[2]);
        $this->assertNotSame($messages[0], $messages[2]);
        // The instructions survive every rephrasing.
        foreach ($messages as $message) {
            $this->assertStringContainsString('Service Schedule tab', $message);
            $this->assertStringContainsString('Scheduled', $message);
        }
    }

    public function test_rotation_never_repeats_the_same_text_two_days_in_a_row(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor);

        // Long enough to wrap around the rephrasing pool.
        foreach (range(1, 8) as $day) {
            $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);
            $this->travel(1)->days();
        }

        $messages = $this->sentMessages($workOrder, $vendor);

        $this->assertCount(8, $messages);
        foreach (range(1, 7) as $i) {
            $this->assertNotSame($messages[$i - 1], $messages[$i], "Days {$i} and ".($i + 1).' sent identical texts.');
            // The first-notice copy is reserved for day 1.
            $this->assertStringNotContainsString('a service schedule has not yet been set', $messages[$i]);
        }
    }
}
