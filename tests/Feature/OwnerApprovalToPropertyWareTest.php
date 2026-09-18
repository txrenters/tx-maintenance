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

    public function test_an_approval_reaches_propertyware_as_a_fixed_line_naming_the_owner(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $captured = $this->spyPropertyWare();

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $captured->calls);

        // One canned line: PropertyWare credits the app's own login, so the
        // owner's name in the comment is the only record of who approved.
        $this->assertSame(
            'I approve this work order. - Maria Delgado, '.now()->format('m/d/Y'),
            $captured->workOrder->approval_comments,
        );

        $workOrder->refresh();
        $this->assertTrue((bool) $workOrder->is_approved);
        $this->assertNotNull($workOrder->approved_date);
    }

    public function test_the_owner_cannot_put_their_own_words_into_propertyware(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $captured = $this->spyPropertyWare();

        // Nothing but the decision is accepted, so a posted comment cannot
        // reach the live PropertyWare record.
        $this->post('/owner-portal/'.$token->token.'/approval', [
            'decision' => 'approved',
            'comment' => 'Please use the same plumber as last time.',
        ])->assertRedirect();

        $this->assertStringNotContainsString(
            'plumber',
            (string) $captured->workOrder->approval_comments,
        );
    }

    public function test_a_disapproval_never_touches_propertyware(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare(expectCall: false);

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'disapproved'])
            ->assertRedirect()->assertSessionHas('success');

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

        $this->assertSame('Not approved.', $conversation->message);
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

    public function test_the_thread_message_is_just_the_decision(): void
    {
        $owner = $this->makeOwner();
        $workOrder = $this->makeWorkOrder($owner);
        $token = $this->makeToken($workOrder, $owner);

        $this->spyPropertyWare();

        $this->post('/owner-portal/'.$token->token.'/approval', ['decision' => 'approved'])
            ->assertRedirect();

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->latest('id')
            ->first();

        $this->assertSame('Approved.', $conversation->message);
    }
}
