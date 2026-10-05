<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\BoardSummaryService;
use App\Services\TenantEasyFixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Tenant Easy Fix board: which work orders it lists, and what each card
 * says about the easy fix.
 */
class EasyFixBoardTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $newStatus;

    private ServiceStatus $easyFixStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->newStatus = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $this->easyFixStatus = ServiceStatus::query()->create([
            'name' => TenantEasyFixService::EASY_FIX_STATUS,
            'description' => TenantEasyFixService::EASY_FIX_STATUS,
        ]);
    }

    /**
     * The board's cards, via a partial Inertia reload of the deferred prop.
     *
     * @return array<int, array<string, mixed>>
     */
    private function boardCards(string $routeName = 'work_orders.easy_fix', string $component = 'WorkOrder/EasyFix'): array
    {
        $response = $this->actingAs(User::factory()->create())->get(route($routeName), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        return collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->keyBy(fn ($card) => (int) $card['work_order_no'])
            ->all();
    }

    private function makeWorkOrder(int $number, array $attributes = []): WorkOrder
    {
        return WorkOrder::query()->create([
            'service_status_id' => $this->newStatus->id,
            'work_order_no' => $number,
            'category' => 'Electrical',
            'type' => 'Service Request',
            'status' => 'Open',
            ...$attributes,
        ]);
    }

    private function logSent(WorkOrder $workOrder, string $event, string $at): void
    {
        DB::table('activity_log')->insert([
            'log_name' => AutomatedMessageLogService::LOG_NAME,
            'description' => 'tenant:sms',
            'event' => $event,
            'subject_type' => $workOrder->getMorphClass(),
            'subject_id' => $workOrder->id,
            'properties' => '{}',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_board_lists_a_work_order_the_automation_flagged(): void
    {
        $this->makeWorkOrder(5001, ['easy_fix_key' => 'gfci_outlet']);

        $this->assertArrayHasKey(5001, $this->boardCards());
    }

    public function test_board_lists_a_work_order_a_coordinator_put_in_the_easy_fix_status(): void
    {
        $this->makeWorkOrder(5002, ['service_status_id' => $this->easyFixStatus->id]);

        $this->assertArrayHasKey(5002, $this->boardCards());
    }

    public function test_board_leaves_out_unrelated_and_closed_work(): void
    {
        $this->makeWorkOrder(5003);
        $this->makeWorkOrder(5004, ['easy_fix_key' => 'gfci_outlet', 'status' => 'Closed']);

        $cards = $this->boardCards();

        $this->assertArrayNotHasKey(5003, $cards);
        $this->assertArrayNotHasKey(5004, $cards);
    }

    public function test_easy_fix_work_orders_still_appear_on_the_main_board(): void
    {
        $this->makeWorkOrder(5005, ['easy_fix_key' => 'gfci_outlet']);

        $this->assertArrayHasKey(5005, $this->boardCards('work_orders.index', 'WorkOrder/Index'));
    }

    public function test_easy_fix_is_registered_for_the_board_summary(): void
    {
        $this->assertContains('easy_fix', WorkOrder::BOARDS);
        $this->assertSame('Tenant Easy Fix', BoardSummaryService::label('easy_fix'));

        $this->makeWorkOrder(5006, ['easy_fix_key' => 'gfci_outlet']);
        $this->makeWorkOrder(5007, ['service_status_id' => $this->easyFixStatus->id]);
        $this->makeWorkOrder(5008);

        $summarized = WorkOrder::query()->forBoard('easy_fix')->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();

        $this->assertEqualsCanonicalizing([5006, 5007], $summarized);
    }

    public function test_each_card_carries_its_easy_fix_details(): void
    {
        $texted = $this->makeWorkOrder(5010, [
            'easy_fix_key' => 'gfci_outlet',
            'service_status_id' => $this->easyFixStatus->id,
        ]);
        $this->logSent($texted, 'tenant_easy_fix_sms', '2026-09-28 10:00:00');
        $this->logSent($texted, 'tenant_easy_fix_follow_up_sms', '2026-09-29 10:00:00');
        $this->logSent($texted, 'tenant_easy_fix_follow_up_sms', '2026-09-30 10:00:00');

        $token = TenantUploadToken::query()->create([
            'token' => Str::random(40),
            'work_order_id' => $texted->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
            'completed_at' => '2026-09-30 12:00:00',
        ]);
        $token->forceFill(['created_at' => '2026-09-28 10:00:00'])->save();

        DB::table('work_order_conversations')->insert([
            'work_order_id' => $texted->id,
            'message' => 'Reset it, works now.',
            'conversation_type' => 'tenant',
            'is_read' => 0,
            'created_at' => '2026-09-29 15:00:00',
            'updated_at' => '2026-09-29 15:00:00',
        ]);

        $this->makeWorkOrder(5011, ['service_status_id' => $this->easyFixStatus->id]);

        $cards = $this->boardCards();

        $details = $cards[5010]['easy_fix'];
        $this->assertSame('outlet', $details['item']);
        $this->assertSame('https://www.youtube.com/watch?v=OUR0GBrDmSg', $details['video_url']);
        $this->assertTrue($details['flagged']);
        $this->assertTrue($details['status_set']);
        $this->assertTrue($details['texted']);
        $this->assertSame(2, $details['check_ins']);
        $this->assertSame(3, $details['check_ins_max']);
        $this->assertStringStartsWith('2026-09-30 10:00:00', (string) $details['last_check_in_at']);
        $this->assertTrue($details['photo_uploaded']);
        $this->assertTrue($details['tenant_replied']);

        $manual = $cards[5011]['easy_fix'];
        $this->assertNull($manual['item']);
        $this->assertFalse($manual['flagged']);
        $this->assertTrue($manual['status_set']);
        $this->assertFalse($manual['texted']);
        $this->assertSame(0, $manual['check_ins']);
        $this->assertFalse($manual['photo_uploaded']);
        $this->assertFalse($manual['tenant_replied']);
    }

    /** A tenant message from before the easy-fix text is not a reply to it. */
    public function test_an_older_tenant_message_is_not_a_reply(): void
    {
        $workOrder = $this->makeWorkOrder(5012, ['easy_fix_key' => 'gfci_outlet']);

        DB::table('work_order_conversations')->insert([
            'work_order_id' => $workOrder->id,
            'message' => 'Outlet in the bathroom is dead.',
            'conversation_type' => 'tenant',
            'is_read' => 0,
            'created_at' => '2026-09-27 09:00:00',
            'updated_at' => '2026-09-27 09:00:00',
        ]);

        $token = TenantUploadToken::query()->create([
            'token' => Str::random(40),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_TENANT_EASY_FIX,
        ]);
        $token->forceFill(['created_at' => '2026-09-28 10:00:00'])->save();

        $this->assertFalse($this->boardCards()[5012]['easy_fix']['tenant_replied']);
    }

    /**
     * The details are built for the whole board at once: adding cards must not
     * add queries, the N+1 that would slow the board as it fills.
     */
    public function test_the_card_details_cost_the_same_queries_however_many_cards(): void
    {
        $this->makeWorkOrder(5020, ['easy_fix_key' => 'gfci_outlet']);
        $few = $this->countBoardQueries();

        foreach (range(5021, 5035) as $number) {
            $workOrder = $this->makeWorkOrder($number, ['easy_fix_key' => 'gfci_outlet']);
            $this->logSent($workOrder, 'tenant_easy_fix_sms', '2026-09-28 10:00:00');
        }

        $this->assertSame($few, $this->countBoardQueries());
    }

    private function countBoardQueries(): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->boardCards();

        return $count;
    }

    /** The ledger read stays on indexed columns: no JSON, as on the HVAC board. */
    public function test_the_board_extracts_no_json(): void
    {
        $this->makeWorkOrder(5040, ['easy_fix_key' => 'gfci_outlet']);

        $offending = [];
        DB::listen(function ($query) use (&$offending) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'json_extract') || str_contains($sql, 'json_unquote') || str_contains($sql, '->')) {
                $offending[] = $query->sql;
            }
        });

        $this->boardCards();

        $this->assertSame([], $offending);
    }

    public function test_the_page_says_whether_the_automatic_texts_are_on(): void
    {
        config(['services.twilio.tenant_easy_fix_sms' => false]);

        $response = $this->actingAs(User::factory()->create())->get(route('work_orders.easy_fix'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('props.easy_fix_texts_enabled'));
    }
}
