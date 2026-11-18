<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\InspectionApp\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Only the work orders a crew may start are handed to the inspection app:
 * emergencies, under the threshold, owner-approved on record, or
 * owner-approved in messages as the AI reads them. The rest wait, and say why.
 */
class InspectionAppHandoffApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_outbox_row_records_the_rule_the_reason_and_the_reading(): void
    {
        $this->assertTrue(Schema::hasColumns('inspection_app_handoffs', ['rule', 'held_reason', 'ai_insight_id']));

        $workOrder = WorkOrder::factory()->create();
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id,
            'type' => AiInsight::TYPE_OWNER_APPROVAL,
            'subject_type' => WorkOrder::class,
            'subject_id' => $workOrder->id,
            'status' => AiInsight::STATUS_OPEN,
            'confidence' => 90,
            'data' => ['approved' => true],
        ]);
        $row = InspectionAppHandoff::query()->create([
            'work_order_id' => $workOrder->id,
            'status' => InspectionAppHandoff::STATUS_HELD,
            'rule' => null,
            'held_reason' => 'Estimate $450.00 is over $300, and no owner approval is recorded',
            'ai_insight_id' => $insight->id,
        ]);

        $this->assertSame('held', $row->fresh()->status);
        $this->assertSame($insight->id, $row->fresh()->aiInsight->id);
        $this->assertSame('owner_approval', AiInsight::TYPE_OWNER_APPROVAL);
        $this->assertSame(
            ['emergency', 'under_threshold', 'owner_approved_recorded', 'owner_approved_in_messages', 'manual'],
            [
                InspectionAppHandoff::RULE_EMERGENCY,
                InspectionAppHandoff::RULE_UNDER_THRESHOLD,
                InspectionAppHandoff::RULE_OWNER_APPROVED_RECORDED,
                InspectionAppHandoff::RULE_OWNER_APPROVED_IN_MESSAGES,
                InspectionAppHandoff::RULE_MANUAL,
            ],
        );
    }

    public function test_the_approval_settings_have_the_offices_defaults(): void
    {
        $this->assertSame(300.0, (float) config('services.inspection_app.handoff_approval_threshold'));
        $this->assertSame(80, (int) config('services.inspection_app.handoff_ai_confidence'));
        $this->assertFalse((bool) config('services.inspection_app.handoff_ai_requires_confirmation'));
        $this->assertSame(20, (int) config('services.inspection_app.handoff_ai_reads_per_sweep'));
        $this->assertTrue((bool) config('services.inspection_app.handoff_ai_reader'));
        $this->assertNull(config('services.inspection_app.handoff_since'));
    }

    // ---- The gate: rules 0, 1, 2 and the held reasons ---------------------

    private function gate(): ApprovalGate
    {
        return app(ApprovalGate::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function workOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create($attributes + ['is_emergency' => false, 'cost_estimate' => null, 'approved_by' => null, 'approved_date' => null]);
    }

    private function thmpOn(WorkOrder $workOrder, ?float $pivotEstimate = null): Vendor
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['propertyware_id' => Vendor::THMP_PROPERTYWARE_ID],
            ['name' => Vendor::THMP_NAME, 'is_active' => true, 'user_id' => User::factory()->create()->id],
        );
        DB::table('work_order_vendors')->insert([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'cost_estimate' => $pivotEstimate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vendor;
    }

    public function test_an_emergency_sends_whatever_it_costs(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['is_emergency' => true, 'cost_estimate' => 2500]));

        $this->assertTrue($decision->send);
        $this->assertSame('emergency', $decision->rule);
    }

    public function test_under_the_threshold_sends_without_asking_the_owner(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['cost_estimate' => 299.99]));

        $this->assertTrue($decision->send);
        $this->assertSame('under_threshold', $decision->rule);
        $this->assertSame(299.99, $decision->estimate);
        $this->assertSame(300.0, $decision->threshold);
    }

    public function test_the_crews_estimate_stands_in_when_the_work_order_has_none(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 180.00);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame(180.0, $decision->estimate);
    }

    public function test_at_or_over_the_threshold_an_approval_on_record_sends(): void
    {
        $byName = $this->gate()->decide($this->workOrder(['cost_estimate' => 300, 'approved_by' => 'J. Owner']));
        $byDate = $this->gate()->decide($this->workOrder(['cost_estimate' => 1200, 'approved_date' => '2026-11-10']));

        $this->assertTrue($byName->send);
        $this->assertSame('owner_approved_recorded', $byName->rule);
        $this->assertTrue($byDate->send);
        $this->assertSame('owner_approved_recorded', $byDate->rule);
    }

    public function test_over_the_threshold_with_nothing_recorded_holds_and_says_why(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['cost_estimate' => 450]));

        $this->assertFalse($decision->send);
        $this->assertNull($decision->rule);
        $this->assertTrue($decision->needsReading);
        $this->assertSame('Estimate $450.00 is over $300, and no owner approval is recorded', $decision->heldReason);
    }

    public function test_no_estimate_at_all_is_held_not_treated_as_cheap(): void
    {
        $none = $this->gate()->decide($this->workOrder());
        $zero = $this->gate()->decide($this->workOrder(['cost_estimate' => 0]));

        $this->assertFalse($none->send);
        $this->assertSame('No estimate on the work order, and no owner approval is recorded', $none->heldReason);
        $this->assertFalse($zero->send);
        $this->assertNull($zero->estimate);
    }

    public function test_a_blank_or_placeholder_approver_is_not_an_approval(): void
    {
        $blank = $this->gate()->decide($this->workOrder(['cost_estimate' => 450, 'approved_by' => '   ']));
        $na = $this->gate()->decide($this->workOrder(['cost_estimate' => 450, 'approved_by' => 'N/A']));

        $this->assertFalse($blank->send);
        $this->assertFalse($na->send);
    }

    public function test_the_threshold_is_the_offices_setting(): void
    {
        config(['services.inspection_app.handoff_approval_threshold' => 500]);

        $this->assertTrue($this->gate()->decide($this->workOrder(['cost_estimate' => 450]))->send);
    }
}
