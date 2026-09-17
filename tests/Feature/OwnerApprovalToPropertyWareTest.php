<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * An owner approving in the portal should stop PropertyWare's own approval
 * queue from emailing them about the same work order (WO#44039). PropertyWare
 * credits whichever login made the call — always the app's — so the owner's
 * name is carried in the approval comment instead.
 */
class OwnerApprovalToPropertyWareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.propertyware.owner_approval_push', true);
    }

    private function makeOwner(string $first = 'Maria', string $last = 'Delgado'): Owner
    {
        return Owner::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first).'@example.com',
            'mobile' => '5125551234',
            'percentage_ownership' => 100,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkOrder(Owner $owner, array $attributes = []): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(
            ['name' => 'New'],
            ['description' => 'New'],
        );

        $building = Building::query()->firstOrCreate(
            ['propertyware_id' => 7101],
            [
                'name' => '123 Demo St',
                'address' => '123 Demo St',
                'portfolio_id' => 900,
            ],
        );

        $workOrder = WorkOrder::factory()->create(array_merge([
            'service_status_id' => $status->id,
            'building_id' => $building->propertyware_id,
            'propertyware_id' => 8157167685,
            'is_approved' => false,
            'approval_comments' => null,
            'approved_date' => null,
        ], $attributes));

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

    /** Captures the work order handed to PropertyWare without calling out. */
    private function spyPropertyWare(bool $expectCall = true): object
    {
        $captured = new class
        {
            public ?WorkOrder $workOrder = null;

            public int $calls = 0;
        };

        $mock = Mockery::mock(PropertyWareService::class);

        $mock->shouldReceive('approvedWorkOrder')
            ->times($expectCall ? 1 : 0)
            ->andReturnUsing(function ($workOrder) use ($captured) {
                $captured->workOrder = $workOrder;
                $captured->calls++;
            });

        $this->app->instance(PropertyWareService::class, $mock);

        return $captured;
    }

    public function test_an_approval_with_a_note_reaches_propertyware_with_the_owner_name_below_it(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $captured = $this->spyPropertyWare();

        $this->post('/owner-portal/'.$token->token.'/approval', [
            'decision' => 'approved',
            'comment' => 'Please use the same plumber as last time.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $captured->calls);

        $comment = $captured->workOrder->approval_comments;

        // The owner's words first, then the line that names them.
        $this->assertStringStartsWith('Please use the same plumber as last time.', $comment);
        $this->assertStringContainsString('- Approved by Maria Delgado, '.now()->format('m/d/Y'), $comment);
        $this->assertLessThan(
            strpos($comment, '- Approved by'),
            strpos($comment, 'Please use the same plumber'),
            'The owner comment must come before the attribution line.',
        );

        $workOrder->refresh();
        $this->assertTrue((bool) $workOrder->is_approved);
        $this->assertNotNull($workOrder->approved_date);
        $this->assertSame($comment, $workOrder->approval_comments);
    }

    public function test_approving_without_a_note_sends_the_attribution_line_alone(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $captured = $this->spyPropertyWare();

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertSame(
            '- Approved by Maria Delgado, '.now()->format('m/d/Y'),
            $captured->workOrder->approval_comments,
        );
    }

    public function test_a_disapproval_never_touches_propertyware(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare(expectCall: false);

        $this->post('/owner-portal/'.$token->token.'/approval', [
            'decision' => 'disapproved',
            'comment' => 'Too expensive, please get another quote.',
        ])->assertRedirect()->assertSessionHas('success');

        // PropertyWare has no declined state, so the work order stays exactly
        // as it was there and the refusal lives in the thread and the bell.
        $workOrder->refresh();
        $this->assertFalse((bool) $workOrder->is_approved);
        $this->assertNull($workOrder->approval_comments);

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->latest('id')
            ->first();

        $this->assertStringContainsString('Too expensive', $conversation->message);
    }

    public function test_the_push_is_off_unless_it_is_switched_on(): void
    {
        config()->set('services.propertyware.owner_approval_push', false);

        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare(expectCall: false);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()->assertSessionHas('success');

        $workOrder->refresh();
        $this->assertFalse((bool) $workOrder->is_approved);
        $this->assertNull($workOrder->approval_comments);
    }

    public function test_a_work_order_with_no_propertyware_id_is_left_alone(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner, ['propertyware_id' => null]);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare(expectCall: false);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertFalse((bool) $workOrder->fresh()->is_approved);
    }

    public function test_a_propertyware_failure_still_leaves_the_decision_recorded(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('approvedWorkOrder')
            ->once()
            ->andThrow(new \RuntimeException('PropertyWare could not be read'));
        $this->app->instance(PropertyWareService::class, $mock);

        // The owner is told their coordinator was notified, and that stays
        // true whatever PropertyWare does.
        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $activity = Activity::query()->where('event', 'owner_portal_approval')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame('approved', $activity->properties['decision']);

        $this->assertNotNull($token->fresh()->responded_at);
    }

    public function test_the_owner_note_is_kept_with_the_decision_for_the_coordinator(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare();

        $this->post('/owner-portal/'.$token->token.'/approval', [
            'decision' => 'approved',
            'comment' => 'Please use the same plumber as last time.',
        ])->assertRedirect();

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->latest('id')
            ->first();

        $this->assertStringContainsString('Please use the same plumber', $conversation->message);
        $this->assertStringContainsString('I approve this work order', $conversation->message);

        $activity = Activity::query()->where('event', 'owner_portal_approval')->latest('id')->first();
        $this->assertSame('Please use the same plumber as last time.', $activity->properties['comment']);
    }

    public function test_an_overlong_note_is_refused(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare(expectCall: false);

        $this->post('/owner-portal/'.$token->token.'/approval', [
            'decision' => 'approved',
            'comment' => str_repeat('a', 1001),
        ])->assertSessionHasErrors('comment');

        $this->assertFalse((bool) $workOrder->fresh()->is_approved);
    }
}
