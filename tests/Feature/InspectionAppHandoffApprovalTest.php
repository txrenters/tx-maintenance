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
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ---- The gate in the hand-off ------------------------------------------

    public function test_consider_sends_a_cheap_work_order_and_names_the_rule(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 120]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('pending', $row->status);
        $this->assertSame('under_threshold', $row->rule);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_consider_holds_an_expensive_work_order_with_the_reason_and_pushes_nothing(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('held', $row->status);
        $this->assertNull($row->rule);
        $this->assertSame('Estimate $450.00 is over $300, and no owner approval is recorded', $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_push_refuses_a_held_row(): void
    {
        Http::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'x']);

        $this->service()->push($row->id);

        Http::assertNothingSent();
        $this->assertSame('held', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_a_held_row_is_decided_again_when_the_work_order_comes_back_through_consider(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
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
        $workOrder = $this->workOrder(['cost_estimate' => 120]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'payload_hash' => 'abc'])->save();

        $workOrder->forceFill(['cost_estimate' => 900])->save();
        $again = $this->service()->consider($workOrder->id, 'status_sync');

        $this->assertSame('pending', $again->status);
        $this->assertSame('under_threshold', $again->rule);
    }

    public function test_the_sweep_releases_a_held_row_once_the_approval_is_on_record(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
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
        $old = $this->workOrder(['cost_estimate' => 120, 'created_date' => '2026-11-20']);
        $new = $this->workOrder(['cost_estimate' => 120, 'created_date' => '2026-12-02']);
        $this->thmpOn($old);
        $this->thmpOn($new);

        $this->assertNull($this->service()->consider($old->id, 'vendor_assigned'));
        $this->assertSame('pending', $this->service()->consider($new->id, 'vendor_assigned')->status);
        $this->assertTrue($this->service()->startedBeforeGoLive($old));
        $this->assertFalse($this->service()->startedBeforeGoLive($new));
    }

    public function test_the_manual_push_by_number_skips_the_filter_and_says_so(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_1'], 201)]);
        $workOrder = $this->workOrder(['cost_estimate' => 900]);
        $this->thmpOn($workOrder);

        $row = $this->service()->pushNow((int) $workOrder->work_order_no);

        $this->assertSame('sent', $row->status);
        $this->assertSame('manual', $row->rule);
    }

    // ---- Rule 3a: the reading releases the row -----------------------------

    private function approvedReading(int $confidence = 92): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)']],
            'approved' => true,
            'confidence' => $confidence,
            'summary' => 'Owner approved the hallway light by text on 2026-11-10.',
        ]]);
    }

    private function noApprovalReading(): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]],
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
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
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
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_reading_that_finds_no_approval_keeps_the_row_held_and_says_what_it_read(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
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
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->approvedReading(70);
        $this->service()->readAndRelease($row->id);

        $this->assertSame('held', $row->fresh()->status);
    }

    public function test_the_confirmation_switch_holds_a_confident_reading_with_the_reading_in_the_reason(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_requires_confirmation' => true]);
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
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
            $workOrder = $this->workOrder(['cost_estimate' => 450, 'description' => "Problem {$n}"]);
            $this->thmpOn($workOrder);
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
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
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
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        Queue::assertNotPushed(ReadOwnerApprovalForHandoff::class);
        $this->assertSame(0, $this->service()->sweep()['read']);
        $this->assertSame('held', $row->fresh()->status);
    }

    // ---- The envelope ------------------------------------------------------

    public function test_the_envelope_carries_the_estimate_the_notes_and_the_approval_block(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_9'], 201)]);
        $workOrder = $this->workOrder(['cost_estimate' => 450, 'work_order_no' => 43761]);
        $this->thmpOn($workOrder);
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
        $workOrder = $this->workOrder(['cost_estimate' => 450, 'approved_by' => 'J. Owner', 'approved_date' => '2026-11-10']);
        $this->thmpOn($workOrder);
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
