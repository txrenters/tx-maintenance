<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\WorkOrderApprovalHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every write to the approval section is kept in a named activity log, so a
 * comment that is filled and then blanked or overwritten can be read back.
 */
class WorkOrderApprovalHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_work_order_created_with_approval_values_records_them(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'is_approved' => true,
            'approval_comments' => 'Owner OK up to $500',
            'approved_date' => '2026-10-27',
        ]);

        $history = WorkOrderApprovalHistory::for($workOrder);

        $this->assertCount(1, $history);
        $this->assertSame('approval_recorded', $history[0]->event);
        $this->assertSame('Owner OK up to $500', $history[0]->properties['new']['approval_comments']);
    }

    public function test_empty_then_filled_then_empty_keeps_every_step_and_the_lost_comment(): void
    {
        $workOrder = WorkOrder::factory()->create(['is_approved' => false, 'approval_comments' => null]);

        $workOrder->update(['is_approved' => true, 'approval_comments' => 'Approved, use warranty vendor']);
        $workOrder->update(['approval_comments' => null]);

        $history = WorkOrderApprovalHistory::for($workOrder);

        $this->assertCount(2, $history);
        $this->assertSame(['approval_comments' => 'Approved, use warranty vendor'], $history[0]->properties['old']);
        $this->assertSame(['approval_comments' => null], $history[0]->properties['new']);
        $this->assertSame(['is_approved' => false, 'approval_comments' => null], $history[1]->properties['old']);
    }

    public function test_a_save_that_only_differs_in_type_or_whitespace_records_nothing(): void
    {
        $workOrder = WorkOrder::factory()->create(['is_approved' => false, 'approval_comments' => 'Approved']);
        $before = WorkOrderApprovalHistory::for($workOrder)->count();

        $workOrder->forceFill(['is_approved' => 0, 'approval_comments' => ' Approved '])->save();
        $workOrder->update(['description' => 'Unrelated edit']);

        $this->assertSame($before, WorkOrderApprovalHistory::for($workOrder)->count());
    }

    public function test_history_rows_stay_out_of_the_notification_bell(): void
    {
        $workOrder = WorkOrder::factory()->create(['is_approved' => false]);
        $workOrder->update(['is_approved' => true]);

        $this->assertDatabaseHas('activity_log', ['log_name' => WorkOrderApprovalHistory::LOG_NAME]);
        $this->assertDatabaseMissing('activity_log', ['log_name' => config('activitylog.default_log_name'), 'subject_id' => $workOrder->id]);
    }

    public function test_the_history_command_prints_the_old_and_new_values(): void
    {
        $workOrder = WorkOrder::factory()->create(['is_approved' => true, 'approval_comments' => 'Go ahead']);
        $workOrder->update(['approval_comments' => null]);

        $this->artisan('work-orders:approval-history', ['work_order_no' => $workOrder->work_order_no])
            ->expectsOutputToContain('Go ahead')
            ->expectsOutputToContain('(blank)')
            ->assertSuccessful();
    }

    public function test_the_nightly_sync_fills_a_blank_approval_date_but_never_replaces_one(): void
    {
        $blank = WorkOrder::factory()->create(['propertyware_id' => 111, 'is_approved' => true, 'approved_date' => null]);
        $dated = WorkOrder::factory()->create(['propertyware_id' => 222, 'is_approved' => true, 'approved_date' => '2026-01-05']);

        Http::fake(['api.propertyware.com/*' => Http::response([
            ['id' => 111, 'approved' => true, 'approvedDate' => '2026-10-27', 'customFields' => []],
            ['id' => 222, 'approved' => true, 'approvedDate' => '2026-10-27', 'customFields' => []],
        ])]);

        $this->artisan('sync:work-order-closing-comments')->assertSuccessful();

        $this->assertSame('2026-10-27', $blank->fresh()->approved_date);
        $this->assertSame('2026-01-05', $dated->fresh()->approved_date);
    }
}
