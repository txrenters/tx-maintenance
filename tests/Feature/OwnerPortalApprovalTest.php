<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OwnerPortalApprovalTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeWorkOrder(Owner $owner, string $statusName = 'Assigned - Waiting on Owner Approval'): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(
            ['name' => $statusName],
            ['description' => $statusName],
        );

        $building = Building::query()->create([
            'propertyware_id' => 7101,
            'name' => '6341 Del Monte Dr',
            'address' => '6341 Del Monte Dr',
            'portfolio_id' => 900,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 43900,
            'building_id' => $building->propertyware_id,
            'description' => 'Water heater is leaking in the garage',
        ]);

        $workOrder->owners()->attach($owner->id);

        return $workOrder;
    }

    private function makeToken(WorkOrder $workOrder, Owner $owner): OwnerPortalToken
    {
        return OwnerPortalToken::create([
            'token' => 'demo-owner-token-'.$workOrder->id.'-'.$owner->id,
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_the_portal_asks_for_approval_while_the_status_waits_on_the_owner(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval.requested', true)
                ->where('approval.decision', null)
            );
    }

    public function test_the_portal_asks_for_approval_on_a_new_work_order(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner, 'New');
        $token = $this->makeToken($workOrder, $owner);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval.requested', true)
                ->where('approval.decision', null)
            );
    }

    public function test_the_portal_does_not_ask_for_approval_on_other_statuses(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner, 'Completed');
        $token = $this->makeToken($workOrder, $owner);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval.requested', false)
            );
    }

    public function test_approving_records_a_thread_message_and_notifies_the_coordinator(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->latest('id')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame($owner->id, $conversation->owner_id);
        $this->assertFalse((bool) $conversation->is_read);
        $this->assertStringContainsString('I approve this work order', $conversation->message);

        $activity = Activity::query()->where('event', 'owner_portal_approval')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('approved', $activity->properties['decision']);
        $this->assertSame($workOrder->id, $activity->properties['work_order_id']);
        $this->assertSame($owner->id, $activity->properties['owner_id']);
        $this->assertStringContainsString('Approved by Owner', $activity->description);

        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_disapproving_records_the_decision_with_follow_up_wording(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'disapproved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->latest('id')
            ->first();

        $this->assertStringContainsString('I do not approve this work order', $conversation->message);

        $activity = Activity::query()->where('event', 'owner_portal_approval')->latest('id')->first();

        $this->assertSame('disapproved', $activity->properties['decision']);
        $this->assertStringContainsString('Owner Did Not Approve', $activity->description);
    }

    public function test_the_recorded_decision_replaces_the_buttons_on_the_next_visit(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved']);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval.requested', true)
                ->where('approval.decision', 'approved')
            );
    }

    public function test_a_co_owners_decision_is_not_shown_as_this_owners(): void
    {
        $owner = $this->makeOwner('5125551234', 'Olivia');
        $coOwner = $this->makeOwner('5125557777', 'Owen');
        $workOrder = $this->makeWorkOrder($owner);
        $workOrder->owners()->attach($coOwner->id);

        $coOwnerToken = $this->makeToken($workOrder, $coOwner);
        $this->post('/owner-portal/'.$coOwnerToken->token.'/approval', ['decision' => 'approved']);

        $token = $this->makeToken($workOrder, $owner);

        $this->get('/owner-portal/'.$token->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('approval.decision', null)
            );
    }

    public function test_an_invalid_decision_is_rejected(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'maybe'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(0, Activity::query()->where('event', 'owner_portal_approval')->count());
    }
}
