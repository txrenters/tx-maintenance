<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\AnsweredNotificationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * An owner's portal decision ("Approved." / "Not approved.") is acted on,
 * not answered: any later owner message on the work order, to any owner,
 * from staff or an automation, takes it off the Inbox's Awaiting reply list.
 */
class OwnerPortalDecisionAnsweredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_reply_to_the_co_owner_answers_the_decision(): void
    {
        // WO #44435: Demetria approved, the vendor-assignment text went to
        // Silvestre, and her "Approved." sat under Awaiting reply for 9 days.
        $workOrder = WorkOrder::factory()->create();
        $decision = $this->decision($workOrder, ownerId: 11);

        $this->outbound($workOrder, 'owner', ['owner_id' => 22]);

        $this->assertNotNull($decision->fresh()->answered_at);
        $this->assertFalse($this->bellRead($decision), 'The bell entry stays for the coordinator.');
    }

    public function test_an_automated_owner_text_answers_the_decision(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $decision = $this->decision($workOrder, ownerId: 11, message: 'Not approved.');

        // Automated rows carry no user; the sender is the WOC number.
        $this->outbound($workOrder, 'owner', ['owner_id' => 11, 'sender_number' => '+15550001111']);

        $this->assertNotNull($decision->fresh()->answered_at);
    }

    public function test_a_later_inbound_owner_message_does_not_answer_it(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $decision = $this->decision($workOrder, ownerId: 11);

        Conversation::create([
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'owner_id' => 22,
            'message' => 'When will someone come?',
            'sender_number' => '+15552222222',
            'is_read' => false,
        ]);

        $this->assertNull($decision->fresh()->answered_at);
    }

    public function test_a_tenant_or_vendor_message_does_not_answer_it(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $decision = $this->decision($workOrder, ownerId: 11);

        $this->outbound($workOrder, 'tenant');
        $this->outbound($workOrder, 'vendor');

        $this->assertNull($decision->fresh()->answered_at);
    }

    public function test_an_owner_message_on_another_work_order_does_not_answer_it(): void
    {
        $decision = $this->decision(WorkOrder::factory()->create(), ownerId: 11);

        $this->outbound(WorkOrder::factory()->create(), 'owner', ['owner_id' => 11]);

        $this->assertNull($decision->fresh()->answered_at);
    }

    public function test_an_ordinary_owner_question_is_not_a_decision(): void
    {
        // Only portal decisions are acted-on-not-answered; a real question an
        // owner texted still waits until a coordinator replies to them.
        $workOrder = WorkOrder::factory()->create();
        $question = Conversation::create([
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'owner_id' => 11,
            'message' => 'Can you send the estimate?',
            'sender_number' => '+15551111111',
            'is_read' => false,
        ]);

        $this->outbound($workOrder, 'owner', ['owner_id' => 22, 'sender_number' => '+15550001111']);

        $this->assertNull($question->fresh()->answered_at);
    }

    public function test_the_backfill_stamps_acted_on_decisions_and_sends_nothing(): void
    {
        Queue::fake();
        Bus::fake();
        Mail::fake();

        $actedOn = WorkOrder::factory()->create();
        $untouched = WorkOrder::factory()->create();

        // Rows written before the hook existed: insert the reply with the
        // hook disabled so only the backfill can stamp the decision.
        $old = $this->decision($actedOn, ownerId: 11);
        $stale = $this->decision($untouched, ownerId: 11);
        Conversation::withoutEvents(fn () => $this->outbound($actedOn, 'owner', ['owner_id' => 22]));
        $rowsBefore = Conversation::query()->withoutGlobalScopes()->count();
        $bellBefore = Activity::query()->where('event', 'owner_portal_approval')->count();

        $stamped = app(AnsweredNotificationReader::class)->backfillAnsweredDecisions();

        $this->assertSame(1, $stamped);
        $this->assertNotNull($old->fresh()->answered_at);
        $this->assertNull($stale->fresh()->answered_at, 'A decision nobody has acted on still waits.');
        $this->assertSame($rowsBefore, Conversation::query()->withoutGlobalScopes()->count(), 'The backfill writes no messages.');
        $this->assertSame($bellBefore, Activity::query()->where('event', 'owner_portal_approval')->count());
        $this->assertFalse($this->bellRead($old));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();

        $this->assertSame(0, app(AnsweredNotificationReader::class)->backfillAnsweredDecisions(), 'A second run finds nothing left.');
    }

    private function decision(WorkOrder $workOrder, int $ownerId, string $message = 'Approved.'): Conversation
    {
        $decision = Conversation::create([
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'owner_id' => $ownerId,
            'message' => $message,
            'sender_number' => 'portal',
            'is_read' => false,
        ]);

        activity()->performedOn($workOrder)->event('owner_portal_approval')
            ->withProperties(['work_order_id' => $workOrder->id, 'owner_id' => $ownerId, 'conversation_id' => $decision->id, 'read' => false])
            ->log('Approved by Owner');

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function outbound(WorkOrder $workOrder, string $type, array $attributes = []): Conversation
    {
        return Conversation::create($attributes + [
            'work_order_id' => $workOrder->id,
            'conversation_type' => $type,
            'message' => 'We have assigned a vendor.',
            'sender_number' => '+15550001111',
            'receiver_number' => '+15552222222',
            'is_read' => true,
        ]);
    }

    private function bellRead(Conversation $decision): bool
    {
        $activity = Activity::query()
            ->where('event', 'owner_portal_approval')
            ->where('properties->conversation_id', $decision->id)
            ->sole();

        return (bool) ($activity->properties['read'] ?? false);
    }
}
