<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AwaitingReplyCounter;
use App\Services\CourtesyCloserService;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two refinements to the awaiting-reply pipeline: threads on closed work
 * orders never count as awaiting (and never spend AI), and a whole-message
 * "thank you" is judged a courtesy closer locally the moment it arrives —
 * no AI call, no waiting for the scheduled classifier.
 */
class CourtesyFilterImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(WorkOrder $workOrder, bool $inbound, string $text, array $attributes = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $text,
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? '+15125550000' : '+15125551111',
            'receiver_number' => $inbound ? '+15125551111' : '+15125550000',
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    private function aiUnavailable(): void
    {
        $this->mock(
            WorkOrderRecommendationService::class,
            fn ($mock) => $mock->shouldReceive('aiStatus')
                ->andReturn(['ready' => false, 'provider' => null])
        );
    }

    // ── Closed work orders leave the awaiting set ─────────────────────────

    public function test_a_thread_on_a_closed_work_order_does_not_count_as_awaiting(): void
    {
        $open = WorkOrder::factory()->create();
        $this->message($open, inbound: true, text: 'The sink is leaking');

        $closed = WorkOrder::factory()->create(['status' => 'Closed']);
        $this->message($closed, inbound: true, text: 'Any update?');

        $canceled = WorkOrder::factory()->create(['status' => 'Canceled By Tenant']);
        $this->message($canceled, inbound: true, text: 'Never mind');

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_a_status_less_work_order_still_counts(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => null]);
        $this->message($workOrder, inbound: true, text: 'Hello?');

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_closing_a_work_order_removes_its_thread_from_the_inbox_awaiting_filter(): void
    {
        $coordinator = $this->user('woc');

        $closed = WorkOrder::factory()->create(['status' => 'Closed']);
        $this->message($closed, inbound: true, text: 'Any update?');

        $response = $this->actingAs($coordinator)
            ->get(route('inbox.index', ['status' => 'awaiting']));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('page')['props']['threads']);

        // Under 'all' the thread stays listed — as history, not as awaiting.
        $all = $this->actingAs($coordinator)->get(route('inbox.index'));

        $threads = $all->viewData('page')['props']['threads'];
        $this->assertCount(1, $threads);
        $this->assertFalse($threads[0]['awaiting']);
    }

    public function test_closed_work_order_threads_are_never_sent_to_the_ai(): void
    {
        $this->aiUnavailable();

        $closed = WorkOrder::factory()->create(['status' => 'Closed']);
        $this->message($closed, inbound: true, text: 'A perfectly judgeable text');
        Cache::flush();

        $result = app(CourtesyCloserService::class)->classify();

        // Not judged, not pending — the thread is invisible to the classifier.
        $this->assertSame(['judged' => 0, 'courtesy' => 0, 'pending' => 0], $result);
    }

    // ── Obvious closers are judged locally, instantly ─────────────────────

    public function test_a_bare_thank_you_stops_counting_the_moment_it_arrives(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Thank you!!');

        // No classify() run, no AI — the created hook judged it.
        $this->assertSame(0, app(AwaitingReplyCounter::class)->count());
    }

    public function test_emoji_only_gratitude_stops_counting_instantly(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: '🙏👍');

        $this->assertSame(0, app(AwaitingReplyCounter::class)->count());
    }

    public function test_an_answer_is_never_treated_as_an_obvious_closer(): void
    {
        foreach (['Ok', 'Yes', 'Sounds good', '🤔', 'Thank you, but when is he coming?'] as $index => $text) {
            $workOrder = WorkOrder::factory()->create();
            $this->message($workOrder, inbound: true, text: $text);
        }

        $this->assertSame(5, app(AwaitingReplyCounter::class)->count());
    }

    public function test_an_mms_is_never_an_obvious_closer(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Thanks', attributes: ['is_mms' => true]);

        // The photo may be proof of completed work; it must keep counting.
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_the_gate_disables_instant_verdicts_too(): void
    {
        config(['services.inbox.courtesy_filter' => false]);

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Thank you!');

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_the_scheduled_run_judges_backlog_closers_without_ai(): void
    {
        $this->aiUnavailable();

        $workOrder = WorkOrder::factory()->create();
        $closer = $this->message($workOrder, inbound: true, text: 'Thanks so much!');

        $other = WorkOrder::factory()->create();
        $this->message($other, inbound: true, text: 'Please fix the sink');

        // Wipe the instant verdict to simulate a pre-existing backlog (e.g.
        // messages that arrived before this feature deployed).
        Cache::flush();

        $result = app(CourtesyCloserService::class)->classify();

        $this->assertSame(1, $result['judged']);
        $this->assertSame(1, $result['courtesy']);
        $this->assertContains($closer->id, app(CourtesyCloserService::class)->courtesyIds());
        // The ambiguous one waits for the AI rather than being guessed at.
        $this->assertSame(1, $result['pending']);
    }
}
