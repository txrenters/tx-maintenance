<?php

namespace Tests\Feature;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Jobs\PushWorkOrderToInspectionApp;
use App\Jobs\ReadOwnerApprovalForHandoff;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\InspectionAppHandoff;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\InspectionApp\ApprovalGate;
use App\Services\InspectionApp\HandoffPayload;
use App\Services\InspectionApp\HandoffService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.inspection_app.url' => 'https://inspection-api.example.test',
            'services.inspection_app.token' => 'trk_live_abc123def456.the-secret-half-of-the-key-43-chars-long-xx',
            'services.inspection_app.handoff' => true,
            'services.inspection_app.handoff_vendor_ids' => '246120584,5466750978,4329930755',
            'services.inspection_app.handoff_vendor_names' => 'Texas Home Maintenance Pros|Crystal Creek Air, LLC|Crystal Creek Pest Control LLC',
            'services.inspection_app.handoff_skip_vendor_ids' => '4802084865',
            'services.inspection_app.handoff_ai_reader' => true,
            // The messages summary has its own tests (InspectionAppHandoffMessagesSummaryTest).
            'services.inspection_app.handoff_messages_summary' => false,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function service(): HandoffService
    {
        return app(HandoffService::class);
    }

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

    /**
     * A work order with THMP on it, carrying the crew's own estimate.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function crewEstimate(float $estimate, array $attributes = []): WorkOrder
    {
        $workOrder = $this->workOrder($attributes);
        $this->thmpOn($workOrder, $estimate);

        return $workOrder->fresh(['vendors']);
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

    public function test_the_crews_estimate_of_300_or_below_sends_without_asking_the_owner(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 300.00);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame('under_threshold', $decision->rule);
        $this->assertSame(300.0, $decision->estimate);
        $this->assertSame(300.0, $decision->threshold);
    }

    public function test_no_estimate_and_no_owner_message_holds_and_the_ai_does_not_read_it(): void
    {
        // The office (2026-11-20): "Hold until there's an estimate".
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertFalse($decision->send);
        $this->assertNull($decision->estimate);
        $this->assertFalse($decision->needsReading);
        $this->assertSame("Waiting for the crew's estimate", $decision->heldReason);
    }

    public function test_no_estimate_with_an_owner_message_holds_and_asks_the_ai_to_read_it(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertFalse($decision->send);
        $this->assertTrue($decision->needsReading);
        $this->assertSame("Waiting for the crew's estimate", $decision->heldReason);
    }

    public function test_an_office_message_alone_is_not_read_before_the_crew_has_quoted(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder);
        Conversation::query()->create([
            'message' => 'Hi, can we go ahead with the light?',
            'sender_number' => '+12813787957',
            'receiver_number' => '+15125550100',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => true,
        ]);

        $this->assertFalse($this->gate()->decide($workOrder->fresh(['vendors']))->needsReading);
    }

    public function test_an_owner_approval_in_messages_sends_before_the_crew_has_quoted(): void
    {
        // The office (2026-11-24): an approval in the messages counts with no estimate.
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        Queue::assertPushed(ReadOwnerApprovalForHandoff::class, fn (ReadOwnerApprovalForHandoff $job) => $job->handoffId === $row->id);

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_in_messages', $row->rule);
        $this->assertNotNull($row->ai_insight_id);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_no_approval_in_messages_keeps_waiting_for_the_crews_estimate(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder);
        $this->ownerSaid($workOrder, 'How much will it be?');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->noApprovalReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('held', $row->status);
        $this->assertStringStartsWith("Waiting for the crew's estimate · AI read 1 owner message and found no approval", $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_an_approved_work_order_goes_before_the_crew_has_quoted(): void
    {
        // The office (2026-11-21): "if it is approve move forward".
        $workOrder = $this->workOrder(['approved_by' => 'J. Owner']);
        $this->thmpOn($workOrder);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame('owner_approved_recorded', $decision->rule);
        $this->assertNull($decision->estimate);
    }

    public function test_a_zero_estimate_with_no_approval_holds(): void
    {
        // The office (2026-11-21): "if estimation is zero but no approve do not forward".
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 0.0);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertFalse($decision->send);
        $this->assertSame(0.0, $decision->estimate);
        $this->assertFalse($decision->needsReading);
        $this->assertSame('Estimate is $0, and no owner approval is recorded', $decision->heldReason);
    }

    public function test_a_zero_estimate_with_an_approval_goes(): void
    {
        $workOrder = $this->workOrder(['approved_date' => '2026-11-20']);
        $this->thmpOn($workOrder, 0.0);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame('owner_approved_recorded', $decision->rule);
    }

    public function test_an_emergency_goes_before_the_crew_has_quoted(): void
    {
        $workOrder = $this->workOrder(['is_emergency' => true]);
        $this->thmpOn($workOrder);

        $this->assertSame('emergency', $this->gate()->decide($workOrder->fresh(['vendors']))->rule);
    }

    public function test_the_propertyware_estimate_on_the_work_order_is_not_the_crews(): void
    {
        // The office (2026-11-20): "not from PW because all of them is zero".
        $workOrder = $this->workOrder(['cost_estimate' => 2500]);
        $this->thmpOn($workOrder, 120.00);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame(120.0, $decision->estimate);
    }

    public function test_an_outside_vendors_quote_never_holds_the_crews_work(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 95.00);
        $outside = Vendor::query()->create(['name' => 'She Nailed It Construction, LLC', 'propertyware_id' => '999000111', 'is_active' => true, 'user_id' => User::factory()->create()->id]);
        DB::table('work_order_vendors')->insert(['work_order_id' => $workOrder->id, 'vendor_id' => $outside->id, 'cost_estimate' => 3300.00, 'created_at' => now(), 'updated_at' => now()]);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame(95.0, $decision->estimate);
    }

    public function test_over_300_an_approval_on_record_sends(): void
    {
        $byName = $this->workOrder(['approved_by' => 'J. Owner']);
        $this->thmpOn($byName, 300.01);
        $byDate = $this->workOrder(['approved_date' => '2026-11-10']);
        $this->thmpOn($byDate, 1200.00);

        $this->assertSame('owner_approved_recorded', $this->gate()->decide($byName->fresh(['vendors']))->rule);
        $this->assertSame('owner_approved_recorded', $this->gate()->decide($byDate->fresh(['vendors']))->rule);
    }

    public function test_301_with_nothing_recorded_holds_and_says_why(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 301.00);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertFalse($decision->send);
        $this->assertNull($decision->rule);
        $this->assertTrue($decision->needsReading);
        $this->assertSame('Estimate $301.00 is over $300, and no owner approval is recorded', $decision->heldReason);
    }

    public function test_a_blank_or_placeholder_approver_is_not_an_approval(): void
    {
        $blank = $this->gate()->decide($this->crewEstimate(450.0, ['approved_by' => '   ']));
        $na = $this->gate()->decide($this->crewEstimate(450.0, ['approved_by' => 'N/A']));

        $this->assertFalse($blank->send);
        $this->assertFalse($na->send);
    }

    public function test_the_threshold_is_the_offices_setting(): void
    {
        config(['services.inspection_app.handoff_approval_threshold' => 500]);

        $this->assertTrue($this->gate()->decide($this->crewEstimate(450.0))->send);
        $this->assertFalse($this->gate()->decide($this->crewEstimate(501.0))->send);
    }

    // ---- The gate in the hand-off ------------------------------------------

    public function test_consider_sends_a_cheap_work_order_and_names_the_rule(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 120.0);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('pending', $row->status);
        $this->assertSame('under_threshold', $row->rule);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_consider_holds_an_expensive_work_order_with_the_reason_and_pushes_nothing(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('held', $row->status);
        $this->assertNull($row->rule);
        $this->assertSame('Estimate $450.00 is over $300, and no owner approval is recorded', $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_push_refuses_a_held_row(): void
    {
        Http::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'x']);

        $this->service()->push($row->id);

        Http::assertNothingSent();
        $this->assertSame('held', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_a_held_row_is_decided_again_when_the_work_order_comes_back_through_consider(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->service()->consider($workOrder->id, 'vendor_assigned');

        $workOrder->forceFill(['approved_by' => 'J. Owner'])->save();
        $row = $this->service()->consider($workOrder->id, 'status_sync');

        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_recorded', $row->rule);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_sent_work_order_is_not_pulled_back_by_a_later_change(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 120.0);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'payload_hash' => 'abc'])->save();

        DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->update(['cost_estimate' => 900]);
        $again = $this->service()->consider($workOrder->id, 'status_sync');

        $this->assertSame('pending', $again->status);
        $this->assertSame('under_threshold', $again->rule);
    }

    public function test_the_sweep_releases_a_held_row_once_the_approval_is_on_record(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->service()->consider($workOrder->id, 'vendor_assigned');
        $workOrder->forceFill(['approved_date' => '2026-11-12'])->save();

        $result = $this->service()->sweep();

        $this->assertSame(1, $result['held']);
        $this->assertSame(1, $result['released']);
        $row = InspectionAppHandoff::query()->where('work_order_id', $workOrder->id)->sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_recorded', $row->rule);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_work_order_from_before_go_live_is_never_handed_off_the_first_time(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_since' => '2026-12-01']);
        $old = $this->workOrder(['created_date' => '2026-11-20']);
        $new = $this->workOrder(['created_date' => '2026-12-02']);
        $this->thmpOn($old, 120.0);
        $this->thmpOn($new, 120.0);

        $this->assertNull($this->service()->consider($old->id, 'vendor_assigned'));
        $this->assertSame('pending', $this->service()->consider($new->id, 'vendor_assigned')->status);
        $this->assertTrue($this->service()->startedBeforeGoLive($old));
        $this->assertFalse($this->service()->startedBeforeGoLive($new));
    }

    public function test_the_manual_push_by_number_skips_the_filter_and_says_so(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_1'], 201)]);
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 900.0);

        $row = $this->service()->pushNow((int) $workOrder->work_order_no);

        $this->assertSame('sent', $row->status);
        $this->assertSame('manual', $row->rule);
    }

    // ---- Rule 3a: the reading releases the row -----------------------------

    private function approvedReading(int $confidence = 92): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'Go ahead with the light.', 'source' => 'owner, 2026-11-10 14:02 (SMS)']],
            'approved' => true,
            'confidence' => $confidence,
            'summary' => 'Owner approved the hallway light by text on 2026-11-10.',
        ]]);
    }

    private function noApprovalReading(): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null, 'source' => null]],
            'approved' => false,
            'confidence' => 90,
            'summary' => 'The owner has not answered.',
        ]]);
    }

    private function ownerSaid(WorkOrder $workOrder, string $message): void
    {
        Conversation::query()->create([
            'message' => $message,
            'sender_number' => '+15125550100',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => false,
        ]);
    }

    public function test_consider_queues_a_reading_for_a_held_work_order_and_the_reading_sends_it(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        Queue::assertPushed(ReadOwnerApprovalForHandoff::class, fn (ReadOwnerApprovalForHandoff $job) => $job->handoffId === $row->id);

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_in_messages', $row->rule);
        $this->assertNotNull($row->ai_insight_id);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_reading_that_finds_no_approval_keeps_the_row_held_and_says_what_it_read(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'How much is the door by itself?');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->noApprovalReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('held', $row->status);
        $this->assertStringContainsString('no owner approval is recorded', $row->held_reason);
        $this->assertStringContainsString('AI read 1 owner message and found no approval (90%)', $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_a_reading_under_the_confidence_bar_does_not_send(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->approvedReading(70);
        $this->service()->readAndRelease($row->id);

        $this->assertSame('held', $row->fresh()->status);
    }

    public function test_the_confirmation_switch_holds_a_confident_reading_with_the_reading_in_the_reason(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_requires_confirmation' => true]);
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('held', $row->status);
        $this->assertStringContainsString('Owner approved in messages (92%)', $row->held_reason);
        $this->assertStringContainsString('enter the approval in PropertyWare to send', $row->held_reason);
        $this->assertNotNull($row->ai_insight_id);
    }

    public function test_the_sweep_reads_held_rows_up_to_the_cap_and_never_the_same_text_twice(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_reads_per_sweep' => 2]);
        foreach ([1, 2, 3] as $n) {
            $workOrder = $this->workOrder(['description' => "Problem {$n}"]);
            $this->thmpOn($workOrder, 450.0);
            $this->service()->consider($workOrder->id, 'vendor_assigned');
        }
        $asked = 0;
        OwnerApprovalReaderAgent::fake(function () use (&$asked) {
            $asked++;

            return ['problems' => [['item' => 'x', 'decision' => 'not_mentioned', 'evidence' => null]], 'approved' => false, 'confidence' => 90, 'summary' => 'Nothing.'];
        });

        $first = $this->service()->sweep();
        $this->assertSame(2, $first['read']);
        $this->assertSame(2, $asked);

        $second = $this->service()->sweep();
        $this->assertSame(1, $second['read']);
        $this->assertSame(3, $asked);

        $third = $this->service()->sweep();
        $this->assertSame(0, $third['read']);
        $this->assertSame(3, $asked);
    }

    public function test_the_sweep_reads_again_only_when_the_owner_writes_again(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $this->noApprovalReading();
        $this->service()->readAndRelease($row->id);

        $this->assertSame(0, $this->service()->sweep()['read']);

        $this->ownerSaid($workOrder, 'Yes, go ahead with the light.');
        $this->approvedReading();
        $result = $this->service()->sweep();

        $this->assertSame(1, $result['read']);
        $this->assertSame(1, $result['released']);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_the_reader_switched_off_leaves_held_rows_waiting_for_the_fields(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_reader' => false]);
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        Queue::assertNotPushed(ReadOwnerApprovalForHandoff::class);
        $this->assertSame(0, $this->service()->sweep()['read']);
        $this->assertSame('held', $row->fresh()->status);
    }

    // ---- Findings from the whole-branch review (2026-11-19) ----------------

    public function test_an_emergency_read_back_from_the_database_still_sends(): void
    {
        // PropertyWare's flag comes back from the database as 1, not true.
        Queue::fake();
        $workOrder = $this->workOrder(['is_emergency' => true, 'cost_estimate' => null]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('pending', $row->status);
        $this->assertSame('emergency', $row->rule);
        $this->assertTrue($this->gate()->decide($workOrder->fresh(['vendors']))->send);
    }

    public function test_a_stored_approving_reading_does_not_send_once_the_reader_is_switched_off(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $this->approvedReading();
        $this->service()->readAndRelease($row->id);
        $this->assertSame('pending', $row->fresh()->status);

        $row->refresh()->forceFill(['status' => 'held', 'rule' => null])->save();
        config(['services.inspection_app.handoff_ai_reader' => false]);

        $this->assertSame(0, $this->service()->sweep()['released']);
        $this->assertSame('held', $row->fresh()->status);
    }

    public function test_a_work_order_closed_while_held_is_never_sent_for_the_first_time(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $workOrder->forceFill(['status' => 'Closed'])->save();

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $this->assertNotSame('pending', $row->fresh()->status);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_a_recorded_approval_releases_a_held_row_without_asking_the_ai_and_without_a_reading_attached(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $asked = 0;
        OwnerApprovalReaderAgent::fake(function () use (&$asked) {
            $asked++;

            return ['problems' => [['item' => 'x', 'decision' => 'not_mentioned', 'evidence' => null, 'source' => null]], 'approved' => false, 'confidence' => 90, 'summary' => 'Nothing.'];
        });
        $workOrder->forceFill(['approved_by' => 'J. Owner'])->save();

        $result = $this->service()->sweep();

        $this->assertSame(1, $result['released']);
        $this->assertSame(0, $result['read']);
        $this->assertSame(0, $asked);
        $row->refresh();
        $this->assertSame('owner_approved_recorded', $row->rule);
        $this->assertNull($row->ai_insight_id);
    }

    public function test_an_old_reading_does_not_ride_along_on_a_send_made_under_another_rule(): void
    {
        $workOrder = $this->workOrder(['approved_by' => 'J. Owner', 'approved_date' => '2026-11-10']);
        $this->thmpOn($workOrder, 450.0);
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 90, 'data' => ['approved' => false, 'valid' => true, 'summary' => 'Owner declined the door.', 'problems' => [['item' => 'door', 'decision' => 'declined', 'evidence' => 'no', 'source' => 'owner']]], 'generated_at' => now(),
        ]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'pending', 'rule' => 'owner_approved_recorded', 'ai_insight_id' => $insight->id]);

        $payload = app(HandoffPayload::class)->build($workOrder->fresh(['vendors']), $row);

        $this->assertNull($payload['approval']['ai_confidence']);
        $this->assertNull($payload['approval']['summary']);
        $this->assertSame([], $payload['approval']['problems']);
    }

    public function test_a_release_never_flips_a_row_that_was_sent_in_the_meantime(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        // Another process read and sent it while this one still holds a stale model.
        InspectionAppHandoff::query()->whereKey($row->id)->update(['status' => 'sent', 'rule' => 'owner_approved_in_messages', 'sent_at' => now()]);

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $this->assertSame('sent', $row->fresh()->status);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_the_reading_job_runs_once_per_row_at_a_time(): void
    {
        $job = new ReadOwnerApprovalForHandoff(42);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(42, $job->uniqueId());
    }

    public function test_the_envelope_never_exceeds_the_receivers_field_limits(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 450.0);
        for ($n = 1; $n <= 55; $n++) {
            WorkOrderNotes::query()->create(['work_order_id' => $workOrder->id, 'subject' => str_repeat('s', 300), 'body' => "Note {$n} ".str_repeat('x', 5000), 'date' => '2026-11-09', 'is_private' => false]);
        }
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 92, 'data' => ['approved' => true, 'valid' => true, 'summary' => str_repeat('s', 2000), 'problems' => [['item' => str_repeat('i', 300), 'decision' => 'approved', 'evidence' => str_repeat('e', 900), 'source' => 'owner']]], 'generated_at' => now(),
        ]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'pending', 'rule' => 'owner_approved_in_messages', 'ai_insight_id' => $insight->id]);

        $payload = app(HandoffPayload::class)->build($workOrder->fresh(['vendors']), $row);

        $this->assertCount(50, $payload['notes']);
        $this->assertStringContainsString('Note 55', $payload['notes'][49]['body']);
        foreach ($payload['notes'] as $note) {
            $this->assertLessThanOrEqual(4000, mb_strlen($note['body']));
            $this->assertLessThanOrEqual(200, mb_strlen((string) $note['subject']));
        }
        $this->assertLessThanOrEqual(1000, mb_strlen($payload['approval']['summary']));
        $this->assertLessThanOrEqual(120, mb_strlen($payload['approval']['problems'][0]['item']));
        $this->assertLessThanOrEqual(500, mb_strlen($payload['approval']['problems'][0]['evidence']));
    }

    // ---- The envelope ------------------------------------------------------

    public function test_the_envelope_carries_the_estimate_the_notes_and_the_approval_block(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_9'], 201)]);
        $workOrder = $this->workOrder(['work_order_no' => 43761]);
        $this->thmpOn($workOrder, 450.0);
        WorkOrderNotes::query()->create(['work_order_id' => $workOrder->id, 'subject' => 'Tenant call', 'body' => 'Light out a week', 'date' => '2026-11-09', 'is_private' => false]);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $this->approvedReading();
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $this->service()->readAndRelease($row->id);

        $this->service()->push($row->id);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            // JSON carries 450.0 as 450; the number is what matters.
            $this->assertEquals(450, $body['work_order']['cost_estimate']);
            $this->assertSame('Light out a week', $body['notes'][0]['body']);
            $this->assertSame('Tenant call', $body['notes'][0]['subject']);
            $this->assertSame('owner_approved_in_messages', $body['approval']['rule']);
            $this->assertEquals(300, $body['approval']['threshold']);
            $this->assertSame(92, $body['approval']['ai_confidence']);
            $this->assertSame('approved', $body['approval']['problems'][0]['decision']);
            $this->assertStringContainsString('hallway light', $body['approval']['summary']);

            return true;
        });
    }

    public function test_the_envelope_names_the_recorded_approver_for_a_recorded_approval(): void
    {
        $workOrder = $this->workOrder(['approved_by' => 'J. Owner', 'approved_date' => '2026-11-10']);
        $this->thmpOn($workOrder, 450.0);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'pending', 'rule' => 'owner_approved_recorded']);

        $payload = app(HandoffPayload::class)->build($workOrder->fresh(['vendors']), $row);

        $this->assertSame('owner_approved_recorded', $payload['approval']['rule']);
        $this->assertSame('J. Owner', $payload['approval']['recorded_by']);
        $this->assertStringStartsWith('2026-11-10', $payload['approval']['recorded_at']);
        $this->assertNull($payload['approval']['ai_confidence']);
        $this->assertSame([], $payload['approval']['problems']);
        $this->assertSame([], $payload['notes']);
    }
}
