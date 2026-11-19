<?php

namespace Tests\Feature;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\InspectionAppHandoff;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two console views of the approval filter: what the AI sent, after the
 * fact; and how the reader would have done on old work orders whose
 * approval is already known, before it is trusted with new ones.
 */
class InspectionAppHandoffCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.inspection_app.handoff_vendor_ids' => Vendor::THMP_PROPERTYWARE_ID,
            'services.inspection_app.handoff_approval_threshold' => 300,
            'services.inspection_app.handoff_ai_reader' => true,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);
        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function thmpWorkOrder(array $attributes): WorkOrder
    {
        // The estimate is the crew's own, on its assignment; PropertyWare's is $0.
        $estimate = $attributes['cost_estimate'] ?? null;
        unset($attributes['cost_estimate']);
        $workOrder = WorkOrder::factory()->create($attributes + ['cost_estimate' => 0]);
        $vendor = Vendor::query()->firstOrCreate(
            ['propertyware_id' => Vendor::THMP_PROPERTYWARE_ID],
            ['name' => Vendor::THMP_NAME, 'is_active' => true, 'user_id' => User::factory()->create()->id],
        );
        DB::table('work_order_vendors')->insert(['work_order_id' => $workOrder->id, 'vendor_id' => $vendor->id, 'cost_estimate' => $estimate, 'created_at' => now(), 'updated_at' => now()]);

        return $workOrder;
    }

    public function test_the_list_shows_what_the_ai_sent_with_its_summary(): void
    {
        $workOrder = $this->thmpWorkOrder(['work_order_no' => 43761, 'cost_estimate' => 450]);
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 92, 'data' => ['approved' => true, 'valid' => true, 'summary' => 'Owner approved the hallway light.', 'problems' => []], 'generated_at' => now(),
        ]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'sent', 'rule' => 'owner_approved_in_messages', 'ai_insight_id' => $insight->id, 'sent_at' => now()]);
        $cheap = $this->thmpWorkOrder(['work_order_no' => 43762, 'cost_estimate' => 100]);
        InspectionAppHandoff::query()->create(['work_order_id' => $cheap->id, 'status' => 'sent', 'rule' => 'under_threshold', 'sent_at' => now()]);

        // Asserted on the captured text rather than with expectsOutputToContain,
        // which can match only one substring per printed line and the whole
        // reading sits on one table row.
        $this->assertSame(0, Artisan::call('inspection-app:handoffs', ['--rule' => 'owner_approved_in_messages']));
        $output = Artisan::output();

        $this->assertStringContainsString('43761', $output);
        // The crew's estimate the rule used, not PropertyWare's $0.
        $this->assertStringContainsString('$450.00', $output);
        $this->assertStringContainsString('92%', $output);
        $this->assertStringContainsString('Owner approved the hallway light.', $output);
        $this->assertStringNotContainsString('43762', $output);
    }

    public function test_the_evaluation_scores_the_reader_against_approvals_already_on_record_and_stores_nothing(): void
    {
        $approved = $this->thmpWorkOrder(['work_order_no' => 100, 'cost_estimate' => 450, 'approved_by' => 'J. Owner', 'status' => 'Closed', 'created_date' => '2026-06-01']);
        Conversation::query()->create(['message' => 'Go ahead please.', 'sender_number' => '+15125550100', 'receiver_number' => '+12813787957', 'work_order_id' => $approved->id, 'conversation_type' => 'owner', 'is_read' => false]);
        $this->thmpWorkOrder(['work_order_no' => 101, 'cost_estimate' => 450, 'approved_by' => null, 'status' => 'Canceled By Tenant', 'created_date' => '2026-06-02']);
        $this->thmpWorkOrder(['work_order_no' => 102, 'cost_estimate' => 90, 'status' => 'Closed', 'created_date' => '2026-06-03']);
        $answers = [
            100 => ['problems' => [['item' => 'light', 'decision' => 'approved', 'evidence' => 'Go ahead please.', 'source' => 'owner (SMS)']], 'approved' => true, 'confidence' => 91, 'summary' => 'Approved.'],
            101 => ['problems' => [['item' => 'door', 'decision' => 'not_mentioned', 'evidence' => null, 'source' => null]], 'approved' => false, 'confidence' => 88, 'summary' => 'No answer.'],
        ];
        OwnerApprovalReaderAgent::fake(function (string $prompt) use ($answers): array {
            preg_match('/WORK ORDER #(\d+)/', $prompt, $match);

            return $answers[(int) $match[1]];
        });

        $this->artisan('inspection-app:evaluate-approval-reader', ['--since' => '2026-01-01', '--limit' => 10])
            ->expectsOutputToContain('#100')
            ->expectsOutputToContain('#101')
            ->doesntExpectOutputToContain('#102')
            ->expectsOutputToContain('Agreed with the record: 2 of 2')
            ->expectsOutputToContain('Would have sent without a recorded approval: 0')
            ->expectsOutputToContain('Would have held an approved one: 0')
            ->assertSuccessful();

        $this->assertSame(0, AiInsight::query()->count());
        $this->assertSame(0, InspectionAppHandoff::query()->count());
    }

    public function test_the_json_output_is_one_reading_per_line_and_nothing_else(): void
    {
        $this->thmpWorkOrder(['work_order_no' => 100, 'cost_estimate' => 450, 'approved_by' => 'J. Owner', 'status' => 'Closed', 'created_date' => '2026-06-01']);
        OwnerApprovalReaderAgent::fake([['problems' => [['item' => 'door', 'decision' => 'not_mentioned', 'evidence' => null, 'source' => null]], 'approved' => false, 'confidence' => 88, 'summary' => 'No answer.']]);

        Artisan::call('inspection-app:evaluate-approval-reader', ['--since' => '2026-01-01', '--limit' => 10, '--json' => true]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(100, $decoded['work_order_no']);
        $this->assertTrue($decoded['recorded']);
    }
}
