<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OwnerApprovalThanksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The thank-you an owner is texted the moment they approve a work order in
 * the owner portal, and how it answers the approval in the Inbox.
 */
class OwnerApprovalThanksTest extends TestCase
{
    use RefreshDatabase;

    private const WOC_NUMBER = '+15550001111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.twilio.maintenance_number' => self::WOC_NUMBER,
            'services.twilio.owner_approval_thanks_sms' => true,
        ]);

        Queue::fake();
    }

    public function test_approving_texts_the_owner_a_thank_you_and_answers_the_approval(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->approve($token);

        $thanks = $this->thanksRow($workOrder);

        $this->assertNotNull($thanks, 'No thank-you was stored on the owner thread.');
        $this->assertSame($owner->id, $thanks->owner_id);
        $this->assertTrue((bool) $thanks->is_read);
        $this->assertSame(self::WOC_NUMBER, $thanks->sender_number);
        $this->assertSame('+15125551234', $thanks->receiver_number);
        $this->assertStringStartsWith(OwnerApprovalThanksService::COPY, $thanks->message);
        $this->assertStringContainsString('/owner-portal/', $thanks->message);
        $this->assertStringContainsString("(Ref: WO#{$workOrder->work_order_no})", $thanks->message);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E\n]*$/', $thanks->message, 'The text must stay GSM-7 (plain ASCII).');

        Queue::assertPushed(SendConversationMessageJob::class, 1);

        $approval = $this->approvalRow($workOrder);

        $this->assertNotNull($approval->answered_at, 'The approval should read as answered in the Inbox.');
        $this->assertFalse((bool) $approval->is_read, 'The approval is still an inbound message.');

        $bell = Activity::query()->where('event', 'owner_portal_approval')->latest('id')->first();

        $this->assertFalse((bool) ($bell->properties['read'] ?? false), 'The bell entry must stay for the coordinator.');

        $log = Activity::query()->where('log_name', 'automated_message')->where('event', 'owner_approval_thanks_sms')->first();

        $this->assertNotNull($log);
        $this->assertSame($workOrder->id, $log->properties['work_order_id']);
        $this->assertSame($owner->id, $log->properties['owner_id']);
    }

    public function test_the_thanks_reaches_only_the_owner_who_approved(): void
    {
        $approver = $this->makeOwner('5125551234', 'Demetria');
        $coOwner = $this->makeOwner('5125559999', 'Silvestre');
        $workOrder = $this->makeWorkOrder($approver);
        $workOrder->owners()->attach($coOwner->id);
        $token = $this->makeToken($workOrder, $approver);

        $this->approve($token);

        $rows = Conversation::query()->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('is_read', true)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($approver->id, $rows->first()->owner_id);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_an_owner_with_no_phone_gets_the_thanks_in_their_portal(): void
    {
        $owner = $this->makeOwner('');
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->approve($token);

        $thanks = $this->thanksRow($workOrder);

        $this->assertNotNull($thanks);
        $this->assertSame($owner->id, $thanks->owner_id);
        $this->assertSame(self::WOC_NUMBER, $thanks->sender_number);
        $this->assertNull($thanks->receiver_number);
        Queue::assertNothingPushed();
        $this->assertNotNull($this->approvalRow($workOrder)->answered_at);
    }

    public function test_not_approving_sends_nothing(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'disapproved'])->assertRedirect();

        $this->assertNull($this->thanksRow($workOrder));
        Queue::assertNothingPushed();
        $this->assertNull($this->approvalRow($workOrder)->answered_at, 'A disapproval still waits on the coordinator.');
    }

    public function test_the_switch_turns_the_thanks_off(): void
    {
        config(['services.twilio.owner_approval_thanks_sms' => false]);

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->approve($token);

        $this->assertNull($this->thanksRow($workOrder));
        Queue::assertNothingPushed();
        $this->assertNull($this->approvalRow($workOrder)->answered_at);
    }

    public function test_a_paused_owner_automation_sends_nothing(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->setAutomationPaused('owner', true);
        $workOrder->save();
        $token = $this->makeToken($workOrder, $owner);

        $this->approve($token);

        $this->assertNull($this->thanksRow($workOrder));
        Queue::assertNothingPushed();
    }

    private function approve(OwnerPortalToken $token): void
    {
        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    private function thanksRow(WorkOrder $workOrder): ?Conversation
    {
        return Conversation::query()->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->where('is_read', true)
            ->latest('id')
            ->first();
    }

    private function approvalRow(WorkOrder $workOrder): Conversation
    {
        return Conversation::query()->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('is_read', false)
            ->firstOrFail();
    }

    private function makeOwner(string $mobile = '5125551234', string $first = 'Olivia'): Owner
    {
        return Owner::query()->create([
            'first_name' => $first,
            'last_name' => 'Owner',
            'email' => strtolower($first).'@example.com',
            'mobile' => $mobile,
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(Owner $owner): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        $building = Building::query()->firstOrCreate(
            ['propertyware_id' => 7101],
            ['name' => '6341 Del Monte Dr', 'address' => '6341 Del Monte Dr', 'portfolio_id' => 900],
        );

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Water heater is leaking in the garage',
        ]);

        $workOrder->owners()->attach($owner->id);

        return $workOrder;
    }

    private function makeToken(WorkOrder $workOrder, Owner $owner): OwnerPortalToken
    {
        return OwnerPortalToken::create([
            'token' => 'thanks-token-'.$workOrder->id.'-'.$owner->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
        ]);
    }
}
