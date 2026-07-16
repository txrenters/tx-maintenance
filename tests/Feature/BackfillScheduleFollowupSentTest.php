<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillScheduleFollowupSentTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): Vendor
    {
        $user = User::factory()->create();
        $user->forceFill(['phone' => '2815550000'])->save();

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
     * Assign the vendor, back-dating the assignment by the given business days.
     */
    private function assignVendor(WorkOrder $workOrder, Vendor $vendor, int $businessDaysAgo): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);

        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update(['created_at' => now()->subWeekdays($businessDaysAgo)]);
    }

    private function followupSentAt(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        return DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->value('schedule_followup_sent_at');
    }

    public function test_it_marks_all_existing_unstamped_assignments(): void
    {
        $vendorA = $this->makeVendor();
        $vendorB = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7001]);
        $this->assignVendor($workOrder, $vendorA, businessDaysAgo: 5);
        $this->assignVendor($workOrder, $vendorB, businessDaysAgo: 1);

        $this->artisan('vendors:backfill-followup-sent')->assertExitCode(0);

        $this->assertNotNull($this->followupSentAt($workOrder, $vendorA));
        $this->assertNotNull($this->followupSentAt($workOrder, $vendorB));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7002]);
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $this->artisan('vendors:backfill-followup-sent', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($this->followupSentAt($workOrder, $vendor));
    }

    public function test_it_does_not_overwrite_an_already_stamped_assignment(): void
    {
        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7003]);
        $this->assignVendor($workOrder, $vendor, businessDaysAgo: 5);

        $stampedAt = now()->subDays(10);
        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update(['schedule_followup_sent_at' => $stampedAt]);

        $this->artisan('vendors:backfill-followup-sent')->assertExitCode(0);

        $this->assertSame(
            $stampedAt->toDateTimeString(),
            Carbon::parse($this->followupSentAt($workOrder, $vendor))->toDateTimeString(),
            'An already-stamped assignment must keep its original timestamp.',
        );
    }

    public function test_after_backfill_the_follow_up_skips_the_backlog_but_still_nudges_new_assignments(): void
    {
        config(['services.twilio.schedule_followup_sms' => true]);
        config(['services.twilio.maintenance_number' => '+12813787957']);
        Queue::fake();

        // An existing overdue assignment that predates go-live.
        $backlogVendor = $this->makeVendor();
        $backlogWo = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7004]);
        $this->assignVendor($backlogWo, $backlogVendor, businessDaysAgo: 5);

        // Backfill marks the current backlog as handled.
        $this->artisan('vendors:backfill-followup-sent')->assertExitCode(0);

        // A brand-new assignment that becomes overdue AFTER go-live.
        $newVendor = $this->makeVendor();
        $newWo = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7005]);
        $this->assignVendor($newWo, $newVendor, businessDaysAgo: 5);

        $this->artisan('vendors:followup-unscheduled')->assertExitCode(0);

        // The backlog vendor is never texted; only the new assignment is.
        $this->assertDatabaseMissing('work_order_conversations', [
            'work_order_id' => $backlogWo->id,
            'vendor_id' => $backlogVendor->id,
        ]);
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $newWo->id,
            'vendor_id' => $newVendor->id,
            'conversation_type' => 'vendor',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }
}
