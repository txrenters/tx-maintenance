<?php

namespace Tests\Feature;

use App\Ai\Agents\TenantEasyFixReplyJudgeAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\EasyFixBoardDetails;
use App\Services\TenantEasyFixReplyJudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The "Ready to close" label on the Tenant Easy Fix board (Earl, 2026-10-09):
 * when the tenant replies after the how-to text, the AI reads the reply and
 * says whether the fix worked, with a confidence. Nothing is closed and no
 * status moves — the label tells a coordinator which cards need only a human
 * close-out.
 */
class TenantEasyFixReplyJudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));

        config([
            'services.ai.easy_fix_reply_judge' => true,
            'services.ai.easy_fix_ready_min_confidence' => 80,
            'ai.providers.openai.key' => 'test-key',
        ]);

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    private function makeWorkOrder(array $attributes = []): WorkOrder
    {
        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => '5125559999',
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'work_order_no' => 44300,
            'source' => 'Tenant Portal',
            'propertyware_id' => 900002,
            'description' => 'Garbage disposal is humming but not turning',
            'category' => 'Garbage Disposal',
            'type' => 'Service Request',
            'tenant_id' => $tenant->id,
            'easy_fix_key' => 'disposal_jammed',
            'easy_fix_assessed_at' => now()->subDays(2),
        ], $attributes));

        TenantUploadToken::query()->create([
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
            'token' => TenantUploadToken::generateUniqueToken(),
            'notified_count' => 1,
            'last_notified_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        $this->message($workOrder, 'Hi Dana, here is a short video that walks you through resetting the disposal.', outbound: true, at: now()->subDays(2));

        return $workOrder;
    }

    private function message(WorkOrder $workOrder, string $text, bool $outbound = false, ?Carbon $at = null): Conversation
    {
        return Conversation::query()->create([
            'message' => $text,
            'sender_number' => $outbound ? '+12813787957' : '+15125559999',
            'receiver_number' => $outbound ? '+15125559999' : '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => $outbound,
            'is_mms' => false,
            'created_at' => $at ?? now(),
            'updated_at' => $at ?? now(),
        ]);
    }

    private function fakeVerdict(bool $resolved, int $confidence, string $reason): void
    {
        TenantEasyFixReplyJudgeAgent::fake([[
            'resolved' => $resolved,
            'confidence' => $confidence,
            'reason' => $reason,
        ]]);
    }

    private function insightFor(WorkOrder $workOrder): ?AiInsight
    {
        return AiInsight::query()
            ->ofType(AiInsight::TYPE_EASY_FIX_READY)
            ->where('work_order_id', $workOrder->id)
            ->orderByDesc('id')
            ->first();
    }

    private function cardFor(WorkOrder $workOrder): array
    {
        return app(EasyFixBoardDetails::class)->for([$workOrder->id])[$workOrder->id];
    }

    public function test_a_reply_that_says_the_fix_worked_labels_the_card_ready_to_close_with_the_confidence(): void
    {
        $workOrder = $this->makeWorkOrder();
        $reply = $this->message($workOrder, 'That worked, the disposal runs again. Thank you!');
        $this->fakeVerdict(true, 92, 'The tenant says the disposal runs again after the reset.');

        $this->artisan('easy-fix:judge-replies')
            ->expectsOutputToContain('#44300: ready to close (92%)')
            ->assertSuccessful();

        $insight = $this->insightFor($workOrder);
        $this->assertNotNull($insight);
        $this->assertSame(92, (int) $insight->confidence);
        $this->assertTrue($insight->subject->is($reply));
        $this->assertTrue($insight->data['ready']);
        $this->assertTrue($insight->data['resolved']);
        $this->assertSame('disposal_jammed', $insight->data['easy_fix_key']);

        $card = $this->cardFor($workOrder);
        $this->assertTrue($card['ready_to_close']);
        $this->assertSame(92, $card['reply_confidence']);
        $this->assertTrue($card['reply_resolved']);
        $this->assertSame('The tenant says the disposal runs again after the reset.', $card['reply_reason']);

        $trail = Activity::query()->where('log_name', TenantEasyFixReplyJudge::LOG_NAME)->sole();
        $this->assertSame('easy_fix_ready', $trail->event);
        $this->assertSame('reply says fixed (92%): The tenant says the disposal runs again after the reset.', $trail->description);
        $this->assertTrue($trail->subject->is($workOrder));

        // No status move, no close: the label is all.
        $this->assertSame('Open', $workOrder->fresh()->status);
    }

    public function test_the_judge_reads_the_reply_with_the_item_and_the_thread_before_it(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'Will try it tonight', at: now()->subDay());
        $this->message($workOrder, 'Hi Dana, checking in on the garbage disposal. Did the video help?', outbound: true, at: now()->subHours(3));
        $this->message($workOrder, 'Yes it is working now');
        $this->fakeVerdict(true, 90, 'Working now.');

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        TenantEasyFixReplyJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Garbage disposal')
            && str_contains($prompt->prompt, 'them: "Will try it tonight"')
            && str_contains($prompt->prompt, 'us: "Hi Dana, checking in on the garbage disposal. Did the video help?"')
            && str_contains($prompt->prompt, 'them (NEWEST, judge this): "Yes it is working now"'));
    }

    public function test_a_confident_yes_below_the_bar_is_not_ready_but_the_reading_is_kept(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'I think so?');
        $this->fakeVerdict(true, 55, 'The tenant is unsure.');

        $this->artisan('easy-fix:judge-replies')
            ->expectsOutputToContain('#44300: not ready (55%)')
            ->assertSuccessful();

        $card = $this->cardFor($workOrder);
        $this->assertFalse($card['ready_to_close']);
        $this->assertTrue($card['reply_resolved']);
        $this->assertSame(55, $card['reply_confidence']);
        $this->assertSame('The tenant is unsure.', $card['reply_reason']);
        $this->assertFalse($this->insightFor($workOrder)->data['ready']);

        $trail = Activity::query()->where('log_name', TenantEasyFixReplyJudge::LOG_NAME)->sole();
        $this->assertSame('easy_fix_not_ready', $trail->event);
        $this->assertSame('reply says fixed, unsure (55%): The tenant is unsure.', $trail->description);
    }

    public function test_a_reply_that_says_it_is_still_broken_is_not_ready(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'Tried the reset button twice, still just hums.');
        $this->fakeVerdict(false, 88, 'The disposal still only hums after the reset.');

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        $card = $this->cardFor($workOrder);
        $this->assertFalse($card['ready_to_close']);
        $this->assertFalse($card['reply_resolved']);
        $this->assertSame(88, $card['reply_confidence']);

        $trail = Activity::query()->where('log_name', TenantEasyFixReplyJudge::LOG_NAME)->sole();
        $this->assertSame('reply says not fixed (88%): The disposal still only hums after the reset.', $trail->description);
    }

    public function test_a_card_with_no_judged_reply_carries_no_reading(): void
    {
        $workOrder = $this->makeWorkOrder();

        $card = $this->cardFor($workOrder);
        $this->assertFalse($card['ready_to_close']);
        $this->assertNull($card['reply_resolved']);
        $this->assertNull($card['reply_confidence']);
        $this->assertNull($card['reply_reason']);
    }

    public function test_each_reply_is_judged_once(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'All good now');
        $prompts = 0;
        TenantEasyFixReplyJudgeAgent::fake(function () use (&$prompts) {
            $prompts++;

            return ['resolved' => true, 'confidence' => 95, 'reason' => 'Fixed.'];
        });

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();
        $this->artisan('easy-fix:judge-replies')
            ->expectsOutputToContain('Nothing to judge')
            ->assertSuccessful();

        $this->assertSame(1, AiInsight::query()->ofType(AiInsight::TYPE_EASY_FIX_READY)->count());
        $this->assertSame(1, $prompts);
    }

    public function test_a_newer_reply_is_judged_again_and_the_newest_reading_wins(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'It works!', at: now()->subHour());
        TenantEasyFixReplyJudgeAgent::fake([
            ['resolved' => true, 'confidence' => 95, 'reason' => 'Works.'],
            ['resolved' => false, 'confidence' => 90, 'reason' => 'It stopped again.'],
        ]);

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();
        $this->assertTrue($this->cardFor($workOrder)['ready_to_close']);

        $this->message($workOrder, 'Never mind, it stopped again');
        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        $card = $this->cardFor($workOrder);
        $this->assertFalse($card['ready_to_close']);
        $this->assertSame('It stopped again.', $card['reply_reason']);
        $this->assertSame(2, AiInsight::query()->ofType(AiInsight::TYPE_EASY_FIX_READY)->count());
    }

    public function test_an_ai_failure_stores_nothing_and_the_next_run_tries_again(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'Fixed, thanks');
        TenantEasyFixReplyJudgeAgent::fake([fn () => throw new RuntimeException('provider timed out')]);

        $this->artisan('easy-fix:judge-replies')
            ->expectsOutputToContain('#44300: AI unavailable')
            ->assertSuccessful();
        $this->assertNull($this->insightFor($workOrder));
        $this->assertFalse($this->cardFor($workOrder)['ready_to_close']);
        $this->assertSame(0, Activity::query()->where('log_name', TenantEasyFixReplyJudge::LOG_NAME)->count());

        // The provider is back.
        $this->fakeVerdict(true, 91, 'Fixed.');
        $this->artisan('easy-fix:judge-replies')->assertSuccessful();
        $this->assertTrue($this->cardFor($workOrder)['ready_to_close']);
    }

    public function test_without_a_provider_or_with_the_switch_off_nothing_is_prompted(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'Fixed, thanks');
        $this->fakeVerdict(true, 95, 'Fixed.');

        config(['ai.providers.openai.key' => null]);
        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        config(['ai.providers.openai.key' => 'test-key', 'services.ai.easy_fix_reply_judge' => false]);
        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        TenantEasyFixReplyJudgeAgent::assertNeverPrompted();
        $this->assertNull($this->insightFor($workOrder));
    }

    public function test_replies_from_before_the_easy_fix_are_not_judged(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'My disposal is humming, please send someone', at: now()->subDays(3));
        $this->fakeVerdict(true, 95, 'Fixed.');

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        TenantEasyFixReplyJudgeAgent::assertNeverPrompted();
    }

    public function test_closed_vendor_assigned_hoa_and_off_board_work_orders_are_skipped(): void
    {
        $closed = $this->makeWorkOrder(['work_order_no' => 44301, 'status' => 'Closed']);
        $this->message($closed, 'Fixed');

        $withVendor = $this->makeWorkOrder(['work_order_no' => 44302]);
        $withVendor->vendors()->attach(Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Ace Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ])->id);
        $this->message($withVendor, 'Fixed');

        $hoa = $this->makeWorkOrder(['work_order_no' => 44303, 'category' => WorkOrder::HOA_VIOLATION_CATEGORY, 'easy_fix_key' => null]);
        ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'Checking for Tenant Easy Fix'],
        );
        $hoa->update(['service_status_id' => ServiceStatus::query()->where('name', 'Checking for Tenant Easy Fix')->value('id')]);
        $this->message($hoa, 'Fixed');

        $offBoard = $this->makeWorkOrder(['work_order_no' => 44304, 'easy_fix_key' => null]);
        $this->message($offBoard, 'Fixed');

        $this->fakeVerdict(true, 95, 'Fixed.');

        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        TenantEasyFixReplyJudgeAgent::assertNeverPrompted();
        $this->assertSame(0, AiInsight::query()->ofType(AiInsight::TYPE_EASY_FIX_READY)->count());
    }

    public function test_one_work_order_can_be_judged_by_its_local_id(): void
    {
        $first = $this->makeWorkOrder(['work_order_no' => 44305]);
        $this->message($first, 'Fixed');
        $second = $this->makeWorkOrder(['work_order_no' => 44306]);
        $this->message($second, 'Fixed too');
        $this->fakeVerdict(true, 95, 'Fixed.');

        $this->artisan('easy-fix:judge-replies', ['--work-order' => $second->id])
            ->expectsOutputToContain('#44306: ready to close (95%)')
            ->assertSuccessful();

        $this->assertNull($this->insightFor($first));
        $this->assertNotNull($this->insightFor($second));
    }

    public function test_the_recommendation_tab_carries_the_reading(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->message($workOrder, 'Working again');
        $this->fakeVerdict(true, 93, 'Working again.');
        $this->artisan('easy-fix:judge-replies')->assertSuccessful();

        $workOrder->recommendation()->create([
            'confidence' => 50,
            'classification' => ['easy_fix_key' => 'disposal_jammed', 'is_tenant_easy_fix' => true],
            'generated_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('work_orders.recommendation.show', $workOrder))
            ->assertOk();

        $this->assertSame([
            'ready' => true,
            'resolved' => true,
            'confidence' => 93,
            'reason' => 'Working again.',
            'judged_at' => now()->toJSON(),
        ], $response->json('recommendation.easy_fix_reply'));
    }
}
