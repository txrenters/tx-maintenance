<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
