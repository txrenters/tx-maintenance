<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Summary popup behind the Sparkles button on every work order board.
 *
 * phpunit.xml blanks every AI key, so aiStatus() is never ready here and these
 * tests exercise the deterministic fallback narrative. That is deliberate: no
 * test may reach a live provider.
 */
class BoardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(string $role = 'woc'): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function serviceStatus(string $name): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => $name],
            ['description' => $name],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(WorkOrder $workOrder, bool $inbound, array $attributes = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $inbound ? 'Any update?' : 'We are on it.',
            'conversation_type' => 'tenant',
            'sender_number' => $inbound ? '+15125550000' : '+15125551111',
            'receiver_number' => $inbound ? '+15125551111' : '+15125550000',
            'work_order_id' => $workOrder->id,
            // is_read is the direction marker: false came from the outside.
            'is_read' => ! $inbound,
        ], $attributes));
    }

    public function test_staff_can_load_a_board_summary(): void
    {
        $this->serviceStatus('New');
        WorkOrder::factory()->create(['status' => 'Open']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $response->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'board',
                    'board_label',
                    'generated_at',
                    'work_orders' => ['total', 'created_today', 'awaiting_first_touch', 'emergencies'],
                    'messages' => ['total_today', 'received_today', 'sent_today', 'failed_last_7_days'],
                    'awaiting_reply' => ['threads', 'oldest_waiting_hours', 'samples'],
                ],
                'summary' => ['headline', 'sections', 'attention'],
                'source',
                'ai_ready',
            ]);

        $this->assertSame('Work Orders', $response->json('stats.board_label'));
    }

    public function test_external_roles_are_refused(): void
    {
        $this->serviceStatus('New');

        foreach (['vendor', 'tenant', 'owner'] as $role) {
            $this->actingAs($this->staffUser($role))
                ->getJson(route('work_orders.summary', ['board' => 'main']))
                ->assertForbidden();
        }
    }

    public function test_an_unknown_board_is_rejected(): void
    {
        $this->serviceStatus('New');

        $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'not_a_board']))
            ->assertStatus(422);
    }

    public function test_the_main_board_excludes_turnovers_lawn_and_inspections(): void
    {
        $this->serviceStatus('New');

        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Turnover', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services', 'category' => 'Lawn Service']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Move Out Inspection']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $this->assertSame(1, $response->json('stats.work_orders.total'));

        $turnovers = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'turnovers']));

        $this->assertSame(1, $turnovers->json('stats.work_orders.total'));
    }

    public function test_a_thread_counts_as_awaiting_reply_only_when_the_last_word_was_theirs(): void
    {
        $this->serviceStatus('New');

        // Tenant wrote last — still owed a reply.
        $waiting = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7001]);
        $this->message($waiting, inbound: false);
        $this->message($waiting, inbound: true);

        // We wrote last — handled.
        $answered = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 7002]);
        $this->message($answered, inbound: true);
        $this->message($answered, inbound: false);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $this->assertSame(1, $response->json('stats.awaiting_reply.threads'));

        $samples = $response->json('stats.awaiting_reply.samples');
        $this->assertCount(1, $samples);
        $this->assertSame(7001, (int) $samples[0]['work_order_no']);
        $this->assertSame('Tenant', $samples[0]['party']);
    }

    public function test_each_party_on_one_work_order_is_its_own_thread(): void
    {
        $this->serviceStatus('New');

        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);

        // Tenant thread: they wrote last.
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'tenant']);

        // Owner thread: they wrote last too — a separate unanswered thread.
        $this->message($workOrder, inbound: true, attributes: ['conversation_type' => 'owner']);

        // Vendor thread: we wrote last, so it is not waiting.
        $this->message($workOrder, inbound: false, attributes: ['conversation_type' => 'vendor']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $this->assertSame(2, $response->json('stats.awaiting_reply.threads'));
    }

    public function test_message_counts_split_by_direction(): void
    {
        $this->serviceStatus('New');

        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: true);
        $this->message($workOrder, inbound: false);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $this->assertSame(3, $response->json('stats.messages.total_today'));
        $this->assertSame(2, $response->json('stats.messages.received_today'));
        $this->assertSame(1, $response->json('stats.messages.sent_today'));
    }

    public function test_undelivered_texts_are_counted(): void
    {
        $this->serviceStatus('New');

        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $this->message($workOrder, inbound: false, attributes: ['twilio_status' => 'failed']);
        $this->message($workOrder, inbound: false, attributes: ['twilio_status' => 'undelivered']);
        $this->message($workOrder, inbound: false, attributes: ['twilio_status' => 'delivered']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $this->assertSame(2, $response->json('stats.messages.failed_last_7_days'));
    }

    public function test_board_filters_narrow_the_figures(): void
    {
        $this->serviceStatus('New');

        WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Electrical']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main', 'category' => 'Plumbing']));

        $this->assertSame(1, $response->json('stats.work_orders.total'));
    }

    public function test_the_vendor_filter_narrows_the_figures(): void
    {
        $this->serviceStatus('New');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Ace Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $assigned = WorkOrder::factory()->create(['status' => 'Open']);
        $assigned->vendors()->attach($vendor->id);
        WorkOrder::factory()->create(['status' => 'Open']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main', 'vendor' => $vendor->id]));

        $this->assertSame(1, $response->json('stats.work_orders.total'));
    }

    public function test_it_falls_back_to_computed_figures_when_ai_is_unavailable(): void
    {
        $this->serviceStatus('New');

        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $this->message($workOrder, inbound: true);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'main']));

        $response->assertOk();

        // No provider key in the test environment, so the narrative is built
        // from the template rather than fetched — and is still populated.
        $this->assertFalse($response->json('ai_ready'));
        $this->assertSame('fallback', $response->json('source'));
        $this->assertNotEmpty($response->json('summary.headline'));
        $this->assertNotEmpty($response->json('summary.sections'));

        // The unanswered thread must surface as something to act on.
        $labels = array_column($response->json('summary.attention'), 'label');
        $this->assertContains('Waiting on a reply', $labels);
    }

    /**
     * The summary's board predicate is a second copy of the one inside
     * WorkOrderController's board methods. If the two ever drift, the popup
     * quietly reports figures for a different set of work orders than the cards
     * on screen — so pin them together here.
     *
     * The board appends its own Paid and Closed buckets after the status
     * columns; those are separate queries, so only the status columns are
     * compared against forBoard('main').
     */
    public function test_the_main_board_predicate_matches_what_the_board_renders(): void
    {
        $this->serviceStatus('New');

        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Electrical']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Turnover', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Move Out Inspection']);
        WorkOrder::factory()->create(['status' => 'Closed', 'type' => 'Repair', 'category' => 'Plumbing']);

        // service_status is a deferred prop, so ask for it explicitly.
        $response = $this->actingAs($this->staffUser())->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $columns = collect($response->json('props.service_status'))
            ->reject(fn ($status) => in_array($status['name'] ?? '', ['Paid', 'Closed'], true));

        $rendered = $columns
            ->flatMap(fn ($status) => collect($status['work_orders'] ?? [])->pluck('id'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $scoped = WorkOrder::query()->forBoard('main')->pluck('id')->sort()->values()->all();

        $this->assertNotEmpty($rendered);
        $this->assertSame($scoped, $rendered);
    }

    public function test_the_waiting_on_payment_board_uses_its_service_status(): void
    {
        $this->serviceStatus('New');
        $waiting = $this->serviceStatus('Approved - Waiting on Payment');

        WorkOrder::factory()->create(['status' => 'Open', 'service_status_id' => $waiting->id]);
        WorkOrder::factory()->create(['status' => 'Open']);

        $response = $this->actingAs($this->staffUser())
            ->getJson(route('work_orders.summary', ['board' => 'waiting_on_payment']));

        $this->assertSame(1, $response->json('stats.work_orders.total'));
    }
}
