<?php

namespace Tests\Feature;

use App\Ai\Agents\InboundMessageTriageAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MessageTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The inbound message triage agent: intent chips on inbox threads and
 * schedule suggestions extracted from texts. Read-only — a wrong or missing
 * verdict must never break the inbox or send anything.
 */
class MessageTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
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
            'is_read' => ! $inbound,
        ], $attributes));
    }

    private function aiReady(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
    }

    private function futureStart(int $days = 3): string
    {
        return Carbon::now(MessageTriageService::TIMEZONE)->addDays($days)->setTime(14, 0)->format('Y-m-d H:i');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function agentReturns(array $items): void
    {
        InboundMessageTriageAgent::fake([['items' => $items]]);
    }

    public function test_triage_tags_the_thread_and_records_a_schedule_suggestion(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: false, text: 'The plumber can come this week.');
        $inbound = $this->message($workOrder, inbound: true, text: 'Can we move it to Tuesday at 2pm instead?');

        $start = $this->futureStart();
        $this->agentReturns([[
            'ref' => 1,
            'intent' => 'reschedule_request',
            'summary' => 'Tenant asks to move the visit.',
            'schedule_start' => $start,
            'schedule_end' => null,
        ]]);

        $this->artisan('inbox:triage-messages')->assertSuccessful();

        $this->assertDatabaseHas('ai_insights', [
            'type' => AiInsight::TYPE_MESSAGE_INTENT,
            'subject_type' => Conversation::class,
            'subject_id' => $inbound->id,
            'work_order_id' => $workOrder->id,
        ]);

        $suggestion = AiInsight::query()->ofType(AiInsight::TYPE_SCHEDULE_SUGGESTION)->sole();
        $this->assertSame($workOrder->id, $suggestion->work_order_id);
        $this->assertSame(AiInsight::STATUS_OPEN, $suggestion->status);
        $this->assertSame($start.':00', data_get($suggestion->data, 'start'));

        // The chip reaches the inbox thread payload.
        $response = $this->actingAs($this->staff())->get(route('inbox.index'));
        $response->assertOk();

        $thread = collect($response->viewData('page')['props']['threads'])
            ->firstWhere('work_order_id', $workOrder->id);
        $this->assertSame('reschedule_request', $thread['intent']['intent']);
    }

    public function test_a_message_is_judged_once_and_none_renders_no_chip(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Appreciate you getting that sorted, really.');

        $this->agentReturns([[
            'ref' => 1,
            'intent' => 'none',
            'summary' => 'Needs nothing.',
            'schedule_start' => null,
            'schedule_end' => null,
        ]]);

        $service = app(MessageTriageService::class);

        $this->assertSame(1, $service->classify()['judged']);
        // Second run: nothing left to judge — the stored verdict holds.
        $this->assertSame(0, $service->classify()['judged']);

        $response = $this->actingAs($this->staff())->get(route('inbox.index'));
        $thread = collect($response->viewData('page')['props']['threads'])
            ->firstWhere('work_order_id', $workOrder->id);
        $this->assertNull($thread['intent']);
    }

    public function test_an_approval_is_tagged_and_an_unknown_intent_falls_to_none(): void
    {
        $this->aiReady();

        $approved = WorkOrder::factory()->create();
        $this->message($approved, inbound: false, text: 'The quote is $450 — how would you like to proceed?');
        $this->message($approved, inbound: true, text: 'Please place service call to fix. Thank you', attributes: ['conversation_type' => 'owner']);

        $novel = WorkOrder::factory()->create();
        $this->message($novel, inbound: true, text: 'The moon is made of cheese.');

        $this->agentReturns([
            ['ref' => 1, 'intent' => 'approval', 'summary' => 'Owner approves placing a service call.', 'schedule_start' => null, 'schedule_end' => null],
            ['ref' => 2, 'intent' => 'made_up_intent', 'summary' => 'Nonsense.', 'schedule_start' => null, 'schedule_end' => null],
        ]);

        $this->artisan('inbox:triage-messages')->assertSuccessful();

        $response = $this->actingAs($this->staff())->get(route('inbox.index'));
        $threads = collect($response->viewData('page')['props']['threads']);

        $this->assertSame(
            'approval',
            $threads->firstWhere('work_order_id', $approved->id)['intent']['intent']
        );
        // An intent the model invented is stored as none and renders no chip.
        $this->assertNull($threads->firstWhere('work_order_id', $novel->id)['intent']);
    }

    public function test_disabled_gate_or_ai_not_ready_does_nothing(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'The sink is still leaking.');

        // AI keys are blank in phpunit.xml — not ready.
        $this->assertSame(['judged' => 0, 'suggested' => 0, 'pending' => 0], app(MessageTriageService::class)->classify());

        $this->aiReady();
        config(['services.inbox.intent_triage' => false]);
        $this->assertSame(['judged' => 0, 'suggested' => 0, 'pending' => 0], app(MessageTriageService::class)->classify());

        $this->assertDatabaseCount('ai_insights', 0);
    }

    public function test_courtesy_verdicts_become_none_without_an_ai_call(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();
        $inbound = $this->message($workOrder, inbound: true, text: 'Thanks so much, appreciate it!');

        Cache::forever('inbox.courtesy_closer_ids', [$inbound->id => true]);
        Cache::forever('inbox.courtesy_checked_ids', [$inbound->id => true]);

        // No fake responses queued: an AI call would throw, so a green run
        // proves the verdict came from the courtesy cache alone.
        InboundMessageTriageAgent::fake();

        $result = app(MessageTriageService::class)->classify();
        $this->assertSame(1, $result['judged']);

        $insight = AiInsight::query()->ofType(AiInsight::TYPE_MESSAGE_INTENT)->sole();
        $this->assertSame('none', data_get($insight->data, 'intent'));
    }

    public function test_past_or_garbage_schedule_dates_are_ignored(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Last Tuesday at 2pm worked great.');

        $this->agentReturns([[
            'ref' => 1,
            'intent' => 'question',
            'summary' => 'Mentions a past visit.',
            'schedule_start' => Carbon::now(MessageTriageService::TIMEZONE)->subDays(3)->format('Y-m-d H:i'),
            'schedule_end' => 'not-a-date',
        ]]);

        $this->artisan('inbox:triage-messages')->assertSuccessful();

        $this->assertSame(0, AiInsight::query()->ofType(AiInsight::TYPE_SCHEDULE_SUGGESTION)->count());
        $this->assertSame(1, AiInsight::query()->ofType(AiInsight::TYPE_MESSAGE_INTENT)->count());
    }

    public function test_a_dismissed_suggestion_never_resurrects(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();
        $inbound = $this->message($workOrder, inbound: true, text: 'Tuesday at 2pm works.');

        AiInsight::query()->create([
            'type' => AiInsight::TYPE_SCHEDULE_SUGGESTION,
            'subject_type' => Conversation::class,
            'subject_id' => $inbound->id,
            'work_order_id' => $workOrder->id,
            'status' => AiInsight::STATUS_DISMISSED,
            'data' => ['start' => $this->futureStart().':00'],
        ]);

        $this->agentReturns([[
            'ref' => 1,
            'intent' => 'appointment_confirmed',
            'summary' => 'Tenant confirms Tuesday.',
            'schedule_start' => $this->futureStart(),
            'schedule_end' => null,
        ]]);

        $this->artisan('inbox:triage-messages')->assertSuccessful();

        $suggestion = AiInsight::query()->ofType(AiInsight::TYPE_SCHEDULE_SUGGESTION)->sole();
        $this->assertSame(AiInsight::STATUS_DISMISSED, $suggestion->status);
    }

    public function test_suggestion_endpoints_list_and_resolve_for_staff_only(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $inbound = $this->message($workOrder, inbound: true, text: 'Tuesday works.');

        $suggestion = AiInsight::query()->create([
            'type' => AiInsight::TYPE_SCHEDULE_SUGGESTION,
            'subject_type' => Conversation::class,
            'subject_id' => $inbound->id,
            'work_order_id' => $workOrder->id,
            'status' => AiInsight::STATUS_OPEN,
            'data' => ['start' => $this->futureStart().':00', 'summary' => 'Tenant proposes Tuesday.', 'party' => 'tenant', 'quote' => 'Tuesday works.'],
        ]);

        $woc = $this->staff();

        $this->actingAs($woc)
            ->getJson(route('work_orders.schedule_suggestions', $workOrder))
            ->assertOk()
            ->assertJsonPath('suggestions.0.id', $suggestion->id)
            ->assertJsonPath('suggestions.0.summary', 'Tenant proposes Tuesday.');

        $this->actingAs($woc)
            ->patchJson(route('ai-insights.status', $suggestion), ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $fresh = $suggestion->fresh();
        $this->assertSame($woc->id, $fresh->resolved_by_user_id);
        $this->assertNotNull($fresh->resolved_at);

        // Resolved suggestions disappear from the list.
        $this->actingAs($woc)
            ->getJson(route('work_orders.schedule_suggestions', $workOrder))
            ->assertOk()
            ->assertJsonCount(0, 'suggestions');

        // Non-staff roles get nothing.
        $this->actingAs($this->staff('accounting'))
            ->getJson(route('work_orders.schedule_suggestions', $workOrder))
            ->assertForbidden();
        $this->actingAs($this->staff('accounting'))
            ->patchJson(route('ai-insights.status', $suggestion), ['status' => 'dismissed'])
            ->assertForbidden();
    }

    public function test_a_missing_insights_table_fails_open_in_the_inbox(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->message($workOrder, inbound: true, text: 'Any update on the repair?');

        Schema::drop('ai_insights');

        $response = $this->actingAs($this->staff())->get(route('inbox.index'));
        $response->assertOk();

        $thread = collect($response->viewData('page')['props']['threads'])
            ->firstWhere('work_order_id', $workOrder->id);
        $this->assertNull($thread['intent']);
    }
}
