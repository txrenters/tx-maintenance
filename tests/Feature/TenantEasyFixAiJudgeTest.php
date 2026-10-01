<?php

namespace Tests\Feature;

use App\Ai\Agents\TenantEasyFixJudgeAgent;
use App\Ai\TenantEasyFixCriteria;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\EasyFixBoardDetails;
use App\Services\TenantEasyFixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The AI judge over the keyword shortlist (Earl, 2026-10-02, option B): a
 * work order is tagged a tenant easy fix only when the keywords find a
 * handbook row AND the AI agrees it is that row.
 */
class TenantEasyFixAiJudgeTest extends TestCase
{
    use RefreshDatabase;

    /** WO#44250's description, word for word. */
    private const WATER_HEATER = "Tenant just moved in today, they turn the gas on, and the water heater won't turn on, need someone to come and turn it on.";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.tenant_easy_fix_sms' => false,
            'services.ai.easy_fix_judge' => true,
            'services.ai.easy_fix_min_confidence' => 70,
        ]);

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    private function aiReady(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
    }

    private function service(): TenantEasyFixService
    {
        return app(TenantEasyFixService::class);
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

        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'work_order_no' => 44250,
            'source' => 'Tenant Portal',
            'propertyware_id' => 900001,
            'lease_id' => 555001,
            'description' => 'Garbage disposal is humming but not turning',
            'category' => 'Garbage Disposal',
            'type' => 'Service Request',
            'tenant_id' => $tenant->id,
        ], $attributes));
    }

    private function storedKey(WorkOrder $workOrder): ?string
    {
        return DB::table('work_orders')->where('id', $workOrder->id)->value('easy_fix_key');
    }

    public function test_the_keywords_shortlist_the_furnace_row_for_the_water_heater_request(): void
    {
        // The root cause of WO#44250: "heater won't turn on" sits inside
        // "water heater won't turn on".
        $keys = array_column(TenantEasyFixCriteria::candidates(mb_strtolower(self::WATER_HEATER), 'General Maintenance'), 'key');

        $this->assertContains('furnace_not_working', $keys);
    }

    public function test_the_water_heater_request_is_not_tagged_when_the_ai_rejects_the_furnace_row(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([[
            'easy_fix_key' => null,
            'confidence' => 95,
            'reason' => 'This is a water heater, not a furnace.',
        ]]);

        $workOrder = $this->makeWorkOrder(['description' => self::WATER_HEATER, 'category' => 'General Maintenance']);

        $this->assertNull($this->service()->assess($workOrder));
        $this->assertNull($this->storedKey($workOrder));
        $this->assertNotNull(DB::table('work_orders')->where('id', $workOrder->id)->value('easy_fix_assessed_at'));

        TenantEasyFixJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "water heater won't turn on")
            && str_contains($prompt->prompt, 'furnace_not_working (furnace)'));

        $verdict = Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->sole();
        $this->assertSame('easy_fix_rejected', $verdict->event);
        $this->assertSame('ai_rejected: This is a water heater, not a furnace.', $verdict->description);
        $this->assertTrue($verdict->subject->is($workOrder));
    }

    public function test_a_shortlisted_item_the_ai_confirms_is_tagged(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([[
            'easy_fix_key' => 'disposal_jammed',
            'confidence' => 90,
            'reason' => 'A humming disposal that will not turn is jammed.',
        ]]);

        $workOrder = $this->makeWorkOrder();

        $this->assertSame('disposal_jammed', $this->service()->assess($workOrder)['key']);
        $this->assertSame('disposal_jammed', $this->storedKey($workOrder));

        $verdict = Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->sole();
        $this->assertSame('easy_fix_tagged', $verdict->event);
        $this->assertSame('ai_confirmed (90%): A humming disposal that will not turn is jammed.', $verdict->description);
    }

    public function test_the_ai_cannot_pick_an_item_the_keywords_did_not_shortlist(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([[
            'easy_fix_key' => 'tripped_breaker',
            'confidence' => 99,
            'reason' => 'Sounds electrical.',
        ]]);

        $workOrder = $this->makeWorkOrder();

        $this->assertNull($this->service()->assess($workOrder));
        $this->assertStringStartsWith('ai_rejected:', $this->service()->judge($workOrder)['reason']);
    }

    public function test_a_verdict_below_the_confidence_bar_is_not_tagged(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([[
            'easy_fix_key' => 'disposal_jammed',
            'confidence' => 55,
            'reason' => 'Possibly a jam, possibly a dead motor.',
        ]]);

        $workOrder = $this->makeWorkOrder();

        $this->assertNull($this->service()->assess($workOrder));
        $this->assertSame(
            'ai_unsure (55%): Possibly a jam, possibly a dead motor.',
            Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->sole()->description,
        );
    }

    public function test_an_ai_failure_means_not_an_easy_fix(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([fn () => throw new RuntimeException('provider timed out')]);

        $workOrder = $this->makeWorkOrder();

        $this->assertNull($this->service()->assess($workOrder));
        $this->assertNull($this->storedKey($workOrder));
        $this->assertSame('ai_unavailable', Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->sole()->description);
    }

    public function test_no_keyword_hit_never_asks_the_ai(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake();

        $workOrder = $this->makeWorkOrder(['description' => 'Fence panel blew down in the storm', 'category' => 'Fence']);

        $this->assertNull($this->service()->assess($workOrder));
        TenantEasyFixJudgeAgent::assertNeverPrompted();
        $this->assertSame(0, Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->count());
    }

    public function test_hoa_and_emergency_work_orders_never_ask_the_ai(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake();

        $this->assertNull($this->service()->assess($this->makeWorkOrder(['category' => WorkOrder::HOA_VIOLATION_CATEGORY])));
        $this->assertNull($this->service()->assess($this->makeWorkOrder(['is_emergency' => true])));

        TenantEasyFixJudgeAgent::assertNeverPrompted();
        $this->assertSame(0, Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->count());
    }

    public function test_with_the_judge_switched_off_the_keywords_decide_alone(): void
    {
        $this->aiReady();
        config(['services.ai.easy_fix_judge' => false]);
        TenantEasyFixJudgeAgent::fake();

        $workOrder = $this->makeWorkOrder();

        $this->assertSame('disposal_jammed', $this->service()->assess($workOrder)['key']);
        TenantEasyFixJudgeAgent::assertNeverPrompted();
    }

    public function test_with_no_ai_provider_configured_the_keywords_decide_alone(): void
    {
        TenantEasyFixJudgeAgent::fake();

        $workOrder = $this->makeWorkOrder();

        $this->assertSame('disposal_jammed', $this->service()->assess($workOrder)['key']);
        $this->assertStringStartsWith('matched:', $this->service()->judge($workOrder)['reason']);
        TenantEasyFixJudgeAgent::assertNeverPrompted();
    }

    public function test_the_board_card_carries_the_ai_reason_and_labels_hoa_violations(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([[
            'easy_fix_key' => 'disposal_jammed',
            'confidence' => 90,
            'reason' => 'A humming disposal that will not turn is jammed.',
        ]]);

        $disposal = $this->makeWorkOrder();
        $this->service()->assess($disposal);

        $hoaByCategory = $this->makeWorkOrder(['work_order_no' => 44278, 'category' => WorkOrder::HOA_VIOLATION_CATEGORY]);
        $hoaByToken = $this->makeWorkOrder(['work_order_no' => 44271, 'category' => 'General Maintenance', 'description' => 'Trash cans visible from the street']);
        TenantUploadToken::query()->create([
            'work_order_id' => $hoaByToken->id,
            'token' => TenantUploadToken::generateUniqueToken(),
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);
        $byHand = $this->makeWorkOrder(['work_order_no' => 44300, 'category' => 'General Maintenance', 'description' => 'Ceiling fan wobbles']);

        $details = app(EasyFixBoardDetails::class)->for([$disposal->id, $hoaByCategory->id, $hoaByToken->id, $byHand->id]);

        $this->assertTrue($details[$disposal->id]['flagged']);
        $this->assertFalse($details[$disposal->id]['hoa']);
        $this->assertSame('ai_confirmed (90%): A humming disposal that will not turn is jammed.', $details[$disposal->id]['verdict_reason']);

        $this->assertTrue($details[$hoaByCategory->id]['hoa']);
        $this->assertTrue($details[$hoaByToken->id]['hoa']);
        $this->assertFalse($details[$byHand->id]['hoa']);
        $this->assertNull($details[$byHand->id]['verdict_reason']);
    }

    public function test_the_tagged_audit_lists_the_tags_the_ai_now_rejects_without_changing_them(): void
    {
        $this->aiReady();
        TenantEasyFixJudgeAgent::fake([
            ['easy_fix_key' => null, 'confidence' => 95, 'reason' => 'This is a water heater, not a furnace.'],
            ['easy_fix_key' => 'disposal_jammed', 'confidence' => 90, 'reason' => 'A jammed disposal.'],
        ]);

        // Both tagged by the keywords alone, before the judge existed.
        $waterHeater = $this->makeWorkOrder([
            'description' => self::WATER_HEATER,
            'category' => 'General Maintenance',
            'easy_fix_key' => 'furnace_not_working',
            'easy_fix_assessed_at' => now()->subDay(),
            'created_date' => now()->subDay(),
        ]);
        $disposal = $this->makeWorkOrder([
            'work_order_no' => 44251,
            'easy_fix_key' => 'disposal_jammed',
            'easy_fix_assessed_at' => now()->subDays(2),
            'created_date' => now()->subDays(2),
        ]);

        $this->artisan('easy-fix:audit', ['--tagged' => true])
            ->expectsTable(['Metric', 'Count'], [
                ['Open work orders tagged an easy fix', 2],
                ['Fresh verdict disagrees with the tag', 1],
            ])
            ->expectsOutputToContain('44250')
            ->doesntExpectOutputToContain('44251')
            ->assertSuccessful();

        // Read-only: the tags are untouched.
        $this->assertSame('furnace_not_working', $this->storedKey($waterHeater));
        $this->assertSame('disposal_jammed', $this->storedKey($disposal));
        $this->assertSame(0, Activity::query()->where('log_name', TenantEasyFixService::VERDICT_LOG)->count());
    }
}
