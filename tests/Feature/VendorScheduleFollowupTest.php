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
     * Assign the vendor to the work order, back-dating the assignment
     * (pivot created_at) by the given number of business days.
     */
    private function assignVendor(WorkOrder $workOrder, Vendor $vendor, int $businessDaysAgo): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);

        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update(['created_at' => now()->subWeekdays($businessDaysAgo)]);
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

    public function test_it_texts_a_vendor_assigned_3_days_ago_with_no_schedule(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertNotNull($this->followupSentAt($workOrder, $vendor), 'The assignment should be stamped as followed-up.');
    }

    public function test_it_does_not_text_when_the_gate_is_off(): void
    {
        // Gate defaults off (see setUp).
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNull($this->followupSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_text_a_work_order_that_already_has_a_schedule(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

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
        $this->assertNull($this->followupSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_text_an_assignment_newer_than_3_business_days(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 0);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_text_a_closed_work_order(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Closed', 'work_order_no' => 6001]);
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

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
        // assignment is stamped so it is not rescanned every day.
        $vendor = $this->makeVendor(phone: null);
        $vendor->update(['name' => 'OWNER VENDOR']);

        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
        $this->assertNotNull($this->followupSentAt($workOrder, $vendor), 'The placeholder assignment should be stamped so it is never rescanned.');
    }

    public function test_it_never_texts_the_same_assignment_twice(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        Queue::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->openWorkOrder();
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);
        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        $this->assertDatabaseCount('work_order_conversations', 1);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }
}
