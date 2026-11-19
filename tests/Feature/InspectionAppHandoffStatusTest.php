<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use App\Services\InspectionApp\HandoffStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The work order page says where its hand-off stands, and why. */
class InspectionAppHandoffStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    public function test_a_held_row_is_reported_with_its_reason_and_reading(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 90, 'data' => ['approved' => false, 'valid' => true, 'summary' => 'The owner has not answered.', 'problems' => [], 'messages_read' => 3], 'generated_at' => now(),
        ]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'Estimate $450.00 is over $300, and no owner approval is recorded', 'ai_insight_id' => $insight->id]);

        $status = app(HandoffStatus::class)->for($workOrder);

        $this->assertSame('held', $status['status']);
        $this->assertStringContainsString('over $300', $status['held_reason']);
        $this->assertSame(90, $status['reading']['confidence']);
        $this->assertSame('The owner has not answered.', $status['reading']['summary']);
    }

    public function test_no_row_means_null_and_a_sent_row_names_its_rule(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->assertNull(app(HandoffStatus::class)->for($workOrder));

        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'sent', 'rule' => 'under_threshold', 'sent_at' => now()]);
        $status = app(HandoffStatus::class)->for($workOrder->fresh());

        $this->assertSame('sent', $status['status']);
        $this->assertSame('under_threshold', $status['rule']);
        $this->assertNull($status['reading']);
    }

    public function test_the_recommendation_json_carries_the_handoff_status(): void
    {
        foreach (['admin', 'woc', 'vendor', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $user = User::factory()->create();
        $user->assignRole('admin');
        $workOrder = WorkOrder::factory()->create();
        WorkOrderRecommendation::query()->create(['work_order_id' => $workOrder->id, 'summary' => 'x', 'issue_type' => 'x', 'vendor_category' => 'x', 'reasoning' => 'x', 'keywords' => []]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'No estimate on the work order, and no owner approval is recorded']);

        $this->actingAs($user)
            ->getJson(route('work_orders.recommendation.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('recommendation.inspection_app_handoff.status', 'held')
            ->assertJsonPath('recommendation.inspection_app_handoff.held_reason', 'No estimate on the work order, and no owner approval is recorded');
    }
}
