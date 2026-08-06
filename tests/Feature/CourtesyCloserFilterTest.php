<?php

namespace Tests\Feature;

use App\Ai\Agents\CourtesyCloserAgent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AwaitingReplyCounter;
use App\Services\UnansweredMessageReport;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The AI courtesy-closer filter: a thread ending in "thank you" stops counting
 * as awaiting a reply, while everything unjudged or doubtful keeps counting.
 */
class CourtesyCloserFilterTest extends TestCase
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

    private function aiReady(): void
    {
        $this->mock(WorkOrderRecommendationService::class, fn ($mock) => $mock
            ->shouldReceive('aiStatus')
            ->andReturn(['ready' => true, 'provider' => 'test']));
    }

    /**
     * prompt() type-hints its return, so the mock must hand back a real
     * StructuredAgentResponse — an array would TypeError, which classify()
     * swallows as an AI failure (fail-open) and no verdict would land.
     *
     * @param  array<int, int>  $refs
     */
    private function response(array $refs): StructuredAgentResponse
    {
        return new StructuredAgentResponse(
            invocationId: 'test',
            structured: ['courtesy_refs' => $refs],
            text: json_encode(['courtesy_refs' => $refs]),
            usage: new Usage,
            meta: new Meta,
        );
    }

    /**
     * @param  array<int, int>  $refs
     */
    private function agentReturns(array $refs): void
    {
        $this->mock(CourtesyCloserAgent::class, fn ($mock) => $mock
            ->shouldReceive('prompt')
            ->andReturn($this->response($refs)));
    }

    private function agentIsNeverAsked(): void
    {
        $this->mock(CourtesyCloserAgent::class, fn ($mock) => $mock
            ->shouldNotReceive('prompt'));
    }

    public function test_a_judged_courtesy_closer_stops_counting_everywhere(): void
    {
        // Warm but wordy — not on the obvious-closer list, so only the AI can
        // wave it off.
        $thanked = WorkOrder::factory()->create();
        $this->message($thanked, inbound: false, text: 'The vendor is assigned.');
        $this->message($thanked, inbound: true, text: 'Awesome, appreciate you getting that sorted');

        $leaking = WorkOrder::factory()->create();
        $this->message($leaking, inbound: true, text: 'The sink is still leaking, any update?');

        $counter = app(AwaitingReplyCounter::class);
        $this->assertSame(2, $counter->cachedCount());

        $this->aiReady();
        // Candidates are handed over in id order, so the closer is ref 1.
        $this->agentReturns([1]);

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        // The classifier busts the badge cache, so the drop shows immediately.
        $this->assertSame(1, app(AwaitingReplyCounter::class)->cachedCount());

        $this->actingAs($this->user('woc'));

        $stats = app(UnansweredMessageReport::class)->stats();
        $this->assertSame(1, $stats['threads']);
        $this->assertSame('The sink is still leaking, any update?', $stats['details'][0]['message']);

        $response = $this->get(route('inbox.index'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['stats']['awaiting']);

        // The thread is still listed under 'all' — just no longer as awaiting.
        $threads = collect($props['threads']);
        $this->assertCount(2, $threads);
        $this->assertFalse($threads->firstWhere('work_order_id', $thanked->id)['awaiting']);
    }

    public function test_unjudged_threads_keep_counting(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Appreciate you, see you then');

        // No classifier run: an ambiguous closer counts until the AI judges
        // it. (A whole-message "thank you" is judged instantly on arrival —
        // see CourtesyFilterImprovementsTest.)
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_mms_photo_replies_are_never_sent_for_judgement(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'thanks', attributes: ['is_mms' => true]);

        $this->aiReady();
        $this->agentIsNeverAsked();

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        // A photo can be proof of completed work; the thread stays counted.
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_a_new_message_after_a_courtesy_closer_counts_again(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Thank you!');

        $this->aiReady();
        $this->agentReturns([1]);
        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        $this->assertSame(0, app(AwaitingReplyCounter::class)->count());

        // The verdict belongs to the "Thank you!" row, not the thread: a newer
        // inbound message makes the thread await a reply again.
        $this->message($workOrder, inbound: true, text: 'Actually, one more thing — the door lock is broken.');

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_switching_the_gate_off_restores_the_full_count(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Thank you!');

        $this->aiReady();
        $this->agentReturns([1]);
        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        $this->assertSame(0, app(AwaitingReplyCounter::class)->count());

        config(['services.inbox.courtesy_filter' => false]);

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_ai_unavailable_is_a_safe_noop(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Appreciate you, see you then');

        // phpunit.xml blanks every AI key, so aiStatus() is not ready.
        $this->agentIsNeverAsked();

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_the_ai_is_shown_the_recent_back_and_forth(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: false, text: 'Does Tuesday at 2pm work for the plumber?');
        $this->message($workOrder, inbound: true, text: 'Yes');

        $this->aiReady();

        $prompt = null;

        // Defined outside the mock's arrow function: an arrow fn captures by
        // value, which would give `use (&$prompt)` a copy to write into.
        $capture = function (string $given) use (&$prompt) {
            $prompt = $given;

            return $this->response([]);
        };

        $this->mock(CourtesyCloserAgent::class, fn ($mock) => $mock
            ->shouldReceive('prompt')
            ->andReturnUsing($capture));

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        // The judgement is contextual: the AI sees what we asked, labelled as
        // us, before the newest message it is judging.
        $this->assertStringContainsString('us: "Does Tuesday at 2pm work for the plumber?"', $prompt);
        $this->assertStringContainsString('them (NEWEST, judge this): "Yes"', $prompt);

        // The agent returned no refs, so the answered-question thread counts.
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }

    public function test_long_messages_are_settled_locally_without_ai(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: str_repeat('Thank you so much for everything. ', 10));

        $this->aiReady();
        $this->agentIsNeverAsked();

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();

        // Judged as needing a reply without an AI call, and not re-judged on
        // the next run either.
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());

        $this->artisan('inbox:classify-courtesy')->assertSuccessful();
        $this->assertSame(1, app(AwaitingReplyCounter::class)->count());
    }
}
