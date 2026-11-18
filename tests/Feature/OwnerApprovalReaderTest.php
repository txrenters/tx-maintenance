<?php

namespace Tests\Feature;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI reads a held work order's description, Notes tab, approval comment
 * and owner threads and says which problems the owner approved, quoting the
 * owner's words. A confident yes lets the hand-off send the work order.
 */
class OwnerApprovalReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.inspection_app.handoff_ai_reader' => true,
            'services.inspection_app.handoff_ai_confidence' => 80,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);
    }

    private function reader(): OwnerApprovalReader
    {
        return app(OwnerApprovalReader::class);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'work_order_no' => 43761,
            'description' => 'Hallway light out, front door sticks, lawn overgrown',
            'specific_location' => 'Upstairs',
            'cost_estimate' => 450,
            'approval_comments' => null,
        ]);
    }

    private function ownerText(WorkOrder $workOrder, string $message, bool $fromOwner, string $at): Conversation
    {
        return Conversation::query()->create([
            'message' => $message,
            'sender_number' => $fromOwner ? '+15125550100' : '+12813787957',
            'receiver_number' => $fromOwner ? '+12813787957' : '+15125550100',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => ! $fromOwner,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function note(WorkOrder $workOrder, string $subject, string $body): WorkOrderNotes
    {
        return WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => $subject,
            'body' => $body,
            'date' => '2026-11-09',
            'is_private' => false,
        ]);
    }

    private function ownerEmail(WorkOrder $workOrder, string $body, bool $fromOwner): OwnerEmailNotification
    {
        $owner = Owner::query()->create(['user_id' => User::factory()->create()->id, 'first_name' => 'Pat', 'last_name' => 'Owner', 'name' => 'Pat Owner', 'email' => 'pat@example.com', 'phone' => '5125550100']);

        return OwnerEmailNotification::query()->create([
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'vendor_id' => null,
            'type' => 'vendor_assignment',
            'direction' => $fromOwner ? 'inbound' : 'outbound',
            'subject' => 'WO 43761',
            'body_html' => '<p>'.$body.'</p>',
            'body_text' => $body,
            'from_email' => $fromOwner ? 'pat@example.com' : 'woc@texasrenters.com',
            'to_email' => $fromOwner ? 'woc@texasrenters.com' : 'pat@example.com',
            'created_at' => '2026-11-11 09:00:00',
            'updated_at' => '2026-11-11 09:00:00',
        ]);
    }

    /**
     * @param  list<array{item: string, decision: string, evidence: ?string}>  $problems
     */
    private function fakeReading(array $problems, bool $approved, int $confidence, string $summary): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => $problems,
            'approved' => $approved,
            'confidence' => $confidence,
            'summary' => $summary,
        ]]);
    }

    public function test_the_text_carries_the_description_notes_approval_comment_and_owner_threads_in_order_and_nothing_else(): void
    {
        $workOrder = $this->workOrder();
        $workOrder->forceFill(['approval_comments' => 'Called owner 11/9'])->save();
        $this->note($workOrder, 'Tenant call', 'Tenant says the light has been out a week');
        $this->ownerText($workOrder, 'Estimate is $450 for the three items, ok to proceed?', false, '2026-11-10 13:00:00');
        $this->ownerText($workOrder, 'Go ahead with the light. Hold off on the door for now.', true, '2026-11-10 14:02:00');
        $this->ownerEmail($workOrder, 'Thanks, confirming the light only.', true);
        Conversation::query()->create([
            'message' => 'Can someone come today?',
            'sender_number' => '+15125550199',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
        ]);

        $text = $this->reader()->text($workOrder);

        $this->assertStringContainsString('WORK ORDER #43761', $text);
        $this->assertStringContainsString('Estimate: $450.00', $text);
        $this->assertStringContainsString('Hallway light out, front door sticks, lawn overgrown', $text);
        $this->assertStringContainsString('APPROVAL FIELDS', $text);
        $this->assertStringContainsString('Called owner 11/9', $text);
        $this->assertStringContainsString('NOTES TAB', $text);
        $this->assertStringContainsString('Tenant says the light has been out a week', $text);
        $this->assertStringContainsString('OWNER THREAD', $text);
        $this->assertStringContainsString('office (2026-11-10 13:00, SMS): "Estimate is $450', $text);
        $this->assertStringContainsString('owner (2026-11-10 14:02, SMS): "Go ahead with the light', $text);
        $this->assertStringContainsString('owner (2026-11-11 09:00, email): "Thanks, confirming the light only."', $text);
        $this->assertStringNotContainsString('Can someone come today?', $text);
        $this->assertLessThan(strpos($text, 'OWNER THREAD'), strpos($text, 'NOTES TAB'));
    }

    public function test_a_confident_reading_with_quotes_is_stored_and_approves(): void
    {
        $workOrder = $this->workOrder();
        $this->ownerText($workOrder, 'Go ahead with the light. Hold off on the door for now.', true, '2026-11-10 14:02:00');
        $this->fakeReading([
            ['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)'],
            ['item' => 'front door', 'decision' => 'declined', 'evidence' => 'Hold off on the door for now. — owner, 2026-11-10 14:02 (SMS)'],
            ['item' => 'lawn', 'decision' => 'not_mentioned', 'evidence' => null],
        ], true, 92, 'Owner approved the hallway light by text on 2026-11-10; declined the door; did not mention the lawn.');

        $insight = $this->reader()->read($workOrder);

        $this->assertNotNull($insight);
        $this->assertSame(AiInsight::TYPE_OWNER_APPROVAL, $insight->type);
        $this->assertSame(WorkOrder::class, $insight->subject_type);
        $this->assertSame($workOrder->id, (int) $insight->subject_id);
        $this->assertSame(92, (int) $insight->confidence);
        $this->assertTrue($this->reader()->approves($insight));
        $this->assertSame($this->reader()->hash($workOrder), $insight->data['text_hash']);
        $this->assertSame(1, $insight->data['messages_read']);
        $this->assertCount(3, $insight->data['problems']);
        $this->assertFalse($this->reader()->isStale($workOrder, $insight));
    }

    public function test_a_reading_under_the_bar_or_with_no_approved_item_does_not_approve(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'ok go — owner']], true, 60, 'Probably.');
        $this->assertFalse($this->reader()->approves($this->reader()->read($workOrder)));

        $this->fakeReading([['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]], false, 95, 'The owner has not answered.');
        $this->assertFalse($this->reader()->approves($this->reader()->read($workOrder)));
    }

    public function test_an_approval_without_the_owners_words_invalidates_the_reading(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => '']], true, 99, 'Approved.');

        $insight = $this->reader()->read($workOrder);

        $this->assertNotNull($insight);
        $this->assertFalse($insight->data['valid']);
        $this->assertFalse($this->reader()->approves($insight));
        $this->assertStringContainsString('without the owner', $insight->data['error']);
    }

    public function test_a_malformed_answer_is_a_failed_reading_never_a_guess(): void
    {
        $workOrder = $this->workOrder();
        OwnerApprovalReaderAgent::fake([['problems' => 'yes', 'approved' => true, 'confidence' => 99, 'summary' => '']]);

        $insight = $this->reader()->read($workOrder);

        $this->assertFalse($insight->data['valid']);
        $this->assertFalse($this->reader()->approves($insight));
    }

    public function test_the_same_text_is_never_sent_twice_and_a_new_message_makes_the_reading_stale(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]], false, 90, 'Nothing yet.');
        $first = $this->reader()->read($workOrder);
        $this->assertFalse($this->reader()->isStale($workOrder, $first));

        $this->ownerText($workOrder, 'Yes go ahead with all of it', true, '2026-11-12 08:00:00');
        $this->assertTrue($this->reader()->isStale($workOrder->fresh(), $first));

        $this->fakeReading([['item' => 'all', 'decision' => 'approved', 'evidence' => 'Yes go ahead with all of it — owner']], true, 95, 'Approved all.');
        $second = $this->reader()->read($workOrder->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($this->reader()->approves($second));
        $this->assertSame(1, AiInsight::query()->ofType(AiInsight::TYPE_OWNER_APPROVAL)->count());
    }

    public function test_the_agents_instructions_say_what_counts_and_that_the_later_message_wins(): void
    {
        $instructions = (string) (new OwnerApprovalReaderAgent)->instructions();

        foreach (['not yet', 'later message', 'only the owner', 'quote', 'not approved'] as $phrase) {
            $this->assertStringContainsStringIgnoringCase($phrase, $instructions);
        }
    }

    public function test_preview_reads_without_storing(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'go — owner']], true, 90, 'Yes.');

        $reading = $this->reader()->preview($workOrder);

        $this->assertTrue($reading['approved']);
        $this->assertSame(0, AiInsight::query()->count());
    }
}
