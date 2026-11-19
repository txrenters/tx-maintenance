# Work-order hand-off approval filter: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The hand-off to the technicians' inspection app sends only the work orders a crew may start: emergencies, under $300, owner-approved on record, or owner-approved in messages as read by AI; everything else is held with a visible reason.

**Architecture:** A new `ApprovalGate` sits between `HandoffRule` (is it our crew's work order?) and the outbox: it decides send-or-hold and names the rule. Held outbox rows carry a reason; a queued job and the five-minute sweep ask `OwnerApprovalReader` (a `TenantEasyFixReplyJudge`-shaped AI service storing one `AiInsight` per work order) to read notes and owner threads, and release the row when the reading is a confident approval. The envelope grows `cost_estimate`, `notes` and `approval`; the inspection app writes them into the visit's notes.

**Tech Stack:** Laravel 12 (PHP 8.4), Laravel AI agents (`Laravel\Ai\Promptable`, structured output), Eloquent, PHPUnit feature tests (SQLite, `php artisan test --filter=…`), Inertia + Vue 3 for the work order page. Inspection side: NestJS + zod + vitest (`npm run test:backend`).

**Spec:** `docs/superpowers/specs/2026-11-19-work-order-handoff-approval-filter-design.md`

## Global Constraints

- Threshold default `300` (USD), config `services.inspection_app.handoff_approval_threshold`.
- AI confidence bar default `80`, config `services.inspection_app.handoff_ai_confidence`.
- `services.inspection_app.handoff_ai_requires_confirmation` default `false`; when true a rule-3a work order is *held* (reason names the reading) instead of sent.
- At most `services.inspection_app.handoff_ai_reads_per_sweep` (default `20`) AI readings per sweep; never re-read unchanged text.
- Go-live: `services.inspection_app.handoff_since` (date, default unset). A work order whose `created_date` is before it is never handed off for the first time. No backfill.
- Rule names, exactly: `emergency`, `under_threshold`, `owner_approved_recorded`, `owner_approved_in_messages`, `manual`.
- An `approved` or `declined` decision without a verbatim quote invalidates the whole reading; an invalid reading never approves.
- The AI never writes PropertyWare's approval fields.
- Only owner-party text is read: `work_order_conversations` with `conversation_type` in (`owner`, `vendor_owner`), and `owner_email_notifications`. Never tenant or vendor threads.
- Envelope: add fields, never rename or drop (contract with the inspection app).
- Commit after every task; messages in the repo's voice (what changed and why, a sentence or two).

## Review Focus

1. A work order with `cost_estimate` `0.00` (PropertyWare sends zero for "not set"): a reasonable person expects it treated as *no estimate* (held), not as under $300. Test in Task 2.
2. `approved_by` holding whitespace or the literal `"N/A"` after a PropertyWare sync: expect *not recorded*. Test in Task 2.
3. The owner's "go ahead" followed later by "actually hold off": expect the later message to win, so the reading says not approved. Prompt instruction and a prompt-content test in Task 4.
4. A held work order whose vendor is later removed, then a new in-house vendor added: expect `consider()` to re-decide rather than resend a stale decision. Test in Task 3.
5. The sweep with 40 held rows and a cap of 20: expect exactly 20 readings, the rest untouched until the next run, and no reading repeated for unchanged text. Test in Task 5.

---

### Task 1: Config, migration, model constants

**Files:**
- Modify: `config/services.php` (the `inspection_app` block, lines 16–37)
- Modify: `.env.example` (after `INSPECTION_APP_HANDOFF_SKIP_VENDOR_IDS`)
- Create: `database/migrations/2026_11_19_000001_add_approval_rule_to_inspection_app_handoffs_table.php`
- Modify: `app/Models/InspectionAppHandoff.php`
- Modify: `app/Models/AiInsight.php`
- Test: `tests/Feature/InspectionAppHandoffApprovalTest.php` (new file; later tasks add to it)

**Interfaces:**
- Produces: config keys `services.inspection_app.{handoff_since, handoff_approval_threshold, handoff_ai_reader, handoff_ai_confidence, handoff_ai_requires_confirmation, handoff_ai_reads_per_sweep}`; `InspectionAppHandoff::STATUS_HELD`, `RULE_EMERGENCY`, `RULE_UNDER_THRESHOLD`, `RULE_OWNER_APPROVED_RECORDED`, `RULE_OWNER_APPROVED_IN_MESSAGES`, `RULE_MANUAL`, columns `rule`, `held_reason`, `ai_insight_id`, relation `aiInsight()`; `AiInsight::TYPE_OWNER_APPROVAL`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Only the work orders a crew may start are handed to the inspection app:
 * emergencies, under the threshold, owner-approved on record, or
 * owner-approved in messages as the AI reads them. The rest wait, and say why.
 */
class InspectionAppHandoffApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_outbox_row_records_the_rule_the_reason_and_the_reading(): void
    {
        $this->assertTrue(Schema::hasColumns('inspection_app_handoffs', ['rule', 'held_reason', 'ai_insight_id']));

        $workOrder = WorkOrder::factory()->create();
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id,
            'type' => AiInsight::TYPE_OWNER_APPROVAL,
            'subject_type' => WorkOrder::class,
            'subject_id' => $workOrder->id,
            'status' => AiInsight::STATUS_OPEN,
            'confidence' => 90,
            'data' => ['approved' => true],
        ]);
        $row = InspectionAppHandoff::query()->create([
            'work_order_id' => $workOrder->id,
            'status' => InspectionAppHandoff::STATUS_HELD,
            'rule' => null,
            'held_reason' => 'Estimate $450.00 is over $300, and no owner approval is recorded',
            'ai_insight_id' => $insight->id,
        ]);

        $this->assertSame('held', $row->fresh()->status);
        $this->assertSame($insight->id, $row->fresh()->aiInsight->id);
        $this->assertSame('owner_approval', AiInsight::TYPE_OWNER_APPROVAL);
        $this->assertSame(
            ['emergency', 'under_threshold', 'owner_approved_recorded', 'owner_approved_in_messages', 'manual'],
            [
                InspectionAppHandoff::RULE_EMERGENCY,
                InspectionAppHandoff::RULE_UNDER_THRESHOLD,
                InspectionAppHandoff::RULE_OWNER_APPROVED_RECORDED,
                InspectionAppHandoff::RULE_OWNER_APPROVED_IN_MESSAGES,
                InspectionAppHandoff::RULE_MANUAL,
            ],
        );
    }

    public function test_the_approval_settings_have_the_offices_defaults(): void
    {
        $this->assertSame(300.0, (float) config('services.inspection_app.handoff_approval_threshold'));
        $this->assertSame(80, (int) config('services.inspection_app.handoff_ai_confidence'));
        $this->assertFalse((bool) config('services.inspection_app.handoff_ai_requires_confirmation'));
        $this->assertSame(20, (int) config('services.inspection_app.handoff_ai_reads_per_sweep'));
        $this->assertTrue((bool) config('services.inspection_app.handoff_ai_reader'));
        $this->assertNull(config('services.inspection_app.handoff_since'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: FAIL — `Schema::hasColumns` false / undefined constant `STATUS_HELD`.

- [ ] **Step 3: Add the config keys**

In `config/services.php`, after the `'handoff_skip_vendor_ids'` line inside `'inspection_app'`:

```php
        // Go-live without a backfill (the office, 2026-11-19): a work order
        // created before this date is never handed off for the first time.
        // ISO date, e.g. 2026-12-01. Unset = every open work order qualifies.
        'handoff_since' => env('INSPECTION_APP_HANDOFF_SINCE'),
        // Only work orders a crew may start are sent (docs/superpowers/specs/
        // 2026-11-19-work-order-handoff-approval-filter-design.md). Under this
        // estimate the office does not ask the owner; at or over it, the owner
        // must have approved -- on record, or in messages as the AI reads them.
        'handoff_approval_threshold' => (float) env('INSPECTION_APP_HANDOFF_APPROVAL_THRESHOLD', 300),
        // The AI reads notes and owner threads for a work order held over the
        // threshold. Off = such work orders wait for the approval fields.
        'handoff_ai_reader' => env('INSPECTION_APP_HANDOFF_AI_READER_ENABLED', true),
        // A reading at or above this confidence sends the work order on its
        // own. The office chose no coordinator step (2026-11-19).
        'handoff_ai_confidence' => (int) env('INSPECTION_APP_HANDOFF_AI_MIN_CONFIDENCE', 80),
        // True holds an AI-read approval instead of sending it, with the reading
        // shown on the work order; the office enters the approval in
        // PropertyWare to send. Off because the office asked for automatic.
        'handoff_ai_requires_confirmation' => env('INSPECTION_APP_HANDOFF_AI_REQUIRES_CONFIRMATION', false),
        // Readings per five-minute sweep, so a backlog cannot run up a bill.
        'handoff_ai_reads_per_sweep' => (int) env('INSPECTION_APP_HANDOFF_AI_READS_PER_SWEEP', 20),
```

In `.env.example`, after the `INSPECTION_APP_HANDOFF_SKIP_VENDOR_IDS` block:

```
# Only work orders a crew may start are handed off. Under the threshold no
# owner approval is needed; at or over it the owner must have approved, on
# record or in messages read by AI. Leave the date unset to hand off every
# open work order; set it at go-live so older work orders are not backfilled.
INSPECTION_APP_HANDOFF_SINCE=
INSPECTION_APP_HANDOFF_APPROVAL_THRESHOLD=300
INSPECTION_APP_HANDOFF_AI_READER_ENABLED=true
INSPECTION_APP_HANDOFF_AI_MIN_CONFIDENCE=80
INSPECTION_APP_HANDOFF_AI_REQUIRES_CONFIRMATION=false
INSPECTION_APP_HANDOFF_AI_READS_PER_SWEEP=20
```

- [ ] **Step 4: Write the migration**

`database/migrations/2026_11_19_000001_add_approval_rule_to_inspection_app_handoffs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rule let a work order through to the inspection app, why a held one
 * is waiting, and the AI reading that spoke for an owner approval found in
 * messages. See the 2026-11-19 approval-filter spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_app_handoffs', function (Blueprint $table): void {
            $table->string('rule', 40)->nullable()->index()->after('reason');
            $table->string('held_reason', 200)->nullable()->after('rule');
            $table->foreignId('ai_insight_id')->nullable()->after('held_reason')->constrained('ai_insights')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_app_handoffs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_insight_id');
            $table->dropColumn(['rule', 'held_reason']);
        });
    }
};
```

- [ ] **Step 5: Add the constants and relation**

In `app/Models/InspectionAppHandoff.php`, after `STATUS_SKIPPED`:

```php
    /**
     * Qualifies as ours, but the approval filter has not let it go yet:
     * over the threshold with no owner approval on record or in messages.
     * held_reason says which. Never pushed while held.
     */
    public const STATUS_HELD = 'held';

    public const RULE_EMERGENCY = 'emergency';

    public const RULE_UNDER_THRESHOLD = 'under_threshold';

    public const RULE_OWNER_APPROVED_RECORDED = 'owner_approved_recorded';

    public const RULE_OWNER_APPROVED_IN_MESSAGES = 'owner_approved_in_messages';

    /** Pushed by number from the console: the office's own lever, no filter. */
    public const RULE_MANUAL = 'manual';
```

and after `workOrder()`:

```php
    /** The AI reading that found the owner's approval, for rule 3a rows. */
    public function aiInsight(): BelongsTo
    {
        return $this->belongsTo(AiInsight::class, 'ai_insight_id');
    }
```

Update the class docblock's status list: `held = qualifies but the approval filter is waiting (held_reason)`.

In `app/Models/AiInsight.php`, after `TYPE_EASY_FIX_READY`:

```php
    /**
     * The AI's reading of a work order's notes and owner threads: which of
     * its problems the owner approved, with the owner's words quoted. One
     * per work order (the subject is the WorkOrder row); a confident yes lets
     * the inspection-app hand-off send it.
     */
    public const TYPE_OWNER_APPROVAL = 'owner_approval';
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add config/services.php .env.example database/migrations/2026_11_19_000001_add_approval_rule_to_inspection_app_handoffs_table.php app/Models/InspectionAppHandoff.php app/Models/AiInsight.php tests/Feature/InspectionAppHandoffApprovalTest.php
git commit -m "feat(handoff): the outbox row names the rule that let a work order through, or why it is held -- settings for the office's \$300 threshold, the AI's confidence bar and a go-live date"
```

---

### Task 2: `ApprovalGate` — rules 0, 1, 2 and the held reasons

**Files:**
- Create: `app/Services/InspectionApp/ApprovalDecision.php`
- Create: `app/Services/InspectionApp/ApprovalGate.php`
- Test: `tests/Feature/InspectionAppHandoffApprovalTest.php`

**Interfaces:**
- Consumes: `InspectionAppHandoff::RULE_*` (Task 1).
- Produces:
  - `ApprovalDecision` readonly value object: `bool $send`, `?string $rule`, `?string $heldReason`, `?float $estimate`, `float $threshold`, `bool $needsReading`.
  - `ApprovalGate::threshold(): float`, `ApprovalGate::estimate(WorkOrder): ?float`, `ApprovalGate::approvalRecorded(WorkOrder): bool`, `ApprovalGate::decide(WorkOrder, ?AiInsight $reading = null): ApprovalDecision`. In this task `decide()` never consults a reading; Task 5 adds rule 3a. The `$reading` parameter exists now so the signature does not change.

- [ ] **Step 1: Write the failing tests**

Add to `InspectionAppHandoffApprovalTest` (and the imports `App\Services\InspectionApp\ApprovalGate`, `App\Models\User`, `App\Models\Vendor`, `Illuminate\Support\Facades\DB`):

```php
    private function gate(): ApprovalGate
    {
        return app(ApprovalGate::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function workOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create($attributes + ['is_emergency' => false, 'cost_estimate' => null, 'approved_by' => null, 'approved_date' => null]);
    }

    private function thmpOn(WorkOrder $workOrder, ?float $pivotEstimate = null): Vendor
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['propertyware_id' => Vendor::THMP_PROPERTYWARE_ID],
            ['name' => Vendor::THMP_NAME, 'is_active' => true, 'user_id' => User::factory()->create()->id],
        );
        DB::table('work_order_vendors')->insert([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'cost_estimate' => $pivotEstimate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vendor;
    }

    public function test_an_emergency_sends_whatever_it_costs(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['is_emergency' => true, 'cost_estimate' => 2500]));

        $this->assertTrue($decision->send);
        $this->assertSame('emergency', $decision->rule);
    }

    public function test_under_the_threshold_sends_without_asking_the_owner(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['cost_estimate' => 299.99]));

        $this->assertTrue($decision->send);
        $this->assertSame('under_threshold', $decision->rule);
        $this->assertSame(299.99, $decision->estimate);
        $this->assertSame(300.0, $decision->threshold);
    }

    public function test_the_crews_estimate_stands_in_when_the_work_order_has_none(): void
    {
        $workOrder = $this->workOrder();
        $this->thmpOn($workOrder, 180.00);

        $decision = $this->gate()->decide($workOrder->fresh(['vendors']));

        $this->assertTrue($decision->send);
        $this->assertSame(180.0, $decision->estimate);
    }

    public function test_at_or_over_the_threshold_an_approval_on_record_sends(): void
    {
        $byName = $this->gate()->decide($this->workOrder(['cost_estimate' => 300, 'approved_by' => 'J. Owner']));
        $byDate = $this->gate()->decide($this->workOrder(['cost_estimate' => 1200, 'approved_date' => '2026-11-10']));

        $this->assertTrue($byName->send);
        $this->assertSame('owner_approved_recorded', $byName->rule);
        $this->assertTrue($byDate->send);
        $this->assertSame('owner_approved_recorded', $byDate->rule);
    }

    public function test_over_the_threshold_with_nothing_recorded_holds_and_says_why(): void
    {
        $decision = $this->gate()->decide($this->workOrder(['cost_estimate' => 450]));

        $this->assertFalse($decision->send);
        $this->assertNull($decision->rule);
        $this->assertTrue($decision->needsReading);
        $this->assertSame('Estimate $450.00 is over $300, and no owner approval is recorded', $decision->heldReason);
    }

    public function test_no_estimate_at_all_is_held_not_treated_as_cheap(): void
    {
        $none = $this->gate()->decide($this->workOrder());
        $zero = $this->gate()->decide($this->workOrder(['cost_estimate' => 0]));

        $this->assertFalse($none->send);
        $this->assertSame('No estimate on the work order, and no owner approval is recorded', $none->heldReason);
        $this->assertFalse($zero->send);
        $this->assertNull($zero->estimate);
    }

    public function test_a_blank_or_placeholder_approver_is_not_an_approval(): void
    {
        $blank = $this->gate()->decide($this->workOrder(['cost_estimate' => 450, 'approved_by' => '   ']));
        $na = $this->gate()->decide($this->workOrder(['cost_estimate' => 450, 'approved_by' => 'N/A']));

        $this->assertFalse($blank->send);
        $this->assertFalse($na->send);
    }

    public function test_the_threshold_is_the_offices_setting(): void
    {
        config(['services.inspection_app.handoff_approval_threshold' => 500]);

        $this->assertTrue($this->gate()->decide($this->workOrder(['cost_estimate' => 450]))->send);
    }
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: FAIL — class `ApprovalGate` not found.

- [ ] **Step 3: Write the value object and the gate**

`app/Services/InspectionApp/ApprovalDecision.php`:

```php
<?php

namespace App\Services\InspectionApp;

/**
 * What the approval filter decided for one work order: send it (and under
 * which rule), or hold it (and why). needsReading is true when the only
 * thing that could still let it go is the AI reading the owner's messages.
 */
final class ApprovalDecision
{
    public function __construct(
        public readonly bool $send,
        public readonly ?string $rule,
        public readonly ?string $heldReason,
        public readonly ?float $estimate,
        public readonly float $threshold,
        public readonly bool $needsReading = false,
    ) {}

    public static function send(string $rule, ?float $estimate, float $threshold): self
    {
        return new self(true, $rule, null, $estimate, $threshold);
    }

    public static function hold(string $reason, ?float $estimate, float $threshold, bool $needsReading): self
    {
        return new self(false, null, $reason, $estimate, $threshold, $needsReading);
    }
}
```

`app/Services/InspectionApp/ApprovalGate.php`:

```php
<?php

namespace App\Services\InspectionApp;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\Vendor;
use App\Models\WorkOrder;

/**
 * May a crew start this work order? The office's rule, applied to every
 * work order the hand-off considers (docs/superpowers/specs/
 * 2026-11-19-work-order-handoff-approval-filter-design.md):
 *
 *   0. An emergency goes, whatever it costs.
 *   1. Under the threshold ($300) goes: the office does not ask the owner.
 *   2. At or over it, an approval on record (approved_by / approved_date,
 *      synced from PropertyWare) goes.
 *   3. Otherwise it waits for the AI's reading of the owner's messages
 *      (Task 5 of the plan), and says why it is waiting.
 *
 * The estimate is the work order's own; the crew's estimate on the vendor
 * pivot stands in when the work order has none. Zero means "not set" the way
 * PropertyWare sends it, so it counts as no estimate, never as cheap.
 */
class ApprovalGate
{
    /** What PropertyWare syncs into approved_by when nobody has. */
    private const NOT_AN_APPROVER = ['', 'n/a', 'na', 'none', 'null', '-'];

    public function threshold(): float
    {
        return max(0.0, (float) config('services.inspection_app.handoff_approval_threshold', 300));
    }

    public function estimate(WorkOrder $workOrder): ?float
    {
        $own = $this->positive($workOrder->cost_estimate);

        if ($own !== null) {
            return $own;
        }

        $vendors = $workOrder->relationLoaded('vendors') ? $workOrder->vendors : $workOrder->vendors()->get();

        return $vendors
            ->map(fn (Vendor $vendor): ?float => $this->positive($vendor->pivot?->cost_estimate))
            ->filter()
            ->max();
    }

    public function approvalRecorded(WorkOrder $workOrder): bool
    {
        $by = strtolower(trim((string) $workOrder->approved_by));

        if ($by !== '' && ! in_array($by, self::NOT_AN_APPROVER, true)) {
            return true;
        }

        return $workOrder->approved_date !== null && trim((string) $workOrder->approved_date) !== '';
    }

    public function decide(WorkOrder $workOrder, ?AiInsight $reading = null): ApprovalDecision
    {
        $threshold = $this->threshold();
        $estimate = $this->estimate($workOrder);

        if ($workOrder->is_emergency === true) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_EMERGENCY, $estimate, $threshold);
        }

        if ($estimate !== null && $estimate < $threshold) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_UNDER_THRESHOLD, $estimate, $threshold);
        }

        if ($this->approvalRecorded($workOrder)) {
            return ApprovalDecision::send(InspectionAppHandoff::RULE_OWNER_APPROVED_RECORDED, $estimate, $threshold);
        }

        return ApprovalDecision::hold($this->heldReason($estimate, $threshold), $estimate, $threshold, true);
    }

    private function heldReason(?float $estimate, float $threshold): string
    {
        $limit = '$'.number_format($threshold, $threshold == floor($threshold) ? 0 : 2);

        return $estimate === null
            ? 'No estimate on the work order, and no owner approval is recorded'
            : sprintf('Estimate $%s is over %s, and no owner approval is recorded', number_format($estimate, 2), $limit);
    }

    private function positive(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/InspectionApp/ApprovalDecision.php app/Services/InspectionApp/ApprovalGate.php tests/Feature/InspectionAppHandoffApprovalTest.php
git commit -m "feat(handoff): the approval gate -- an emergency or a repair under \$300 goes, an owner approval on record goes, anything else waits and says why"
```

---

### Task 3: The gate in the hand-off: held rows, go-live date, re-deciding

**Files:**
- Modify: `app/Services/InspectionApp/HandoffService.php`
- Modify: `app/Console/Commands/PushWorkOrdersToInspectionApp.php` (output line)
- Test: `tests/Feature/InspectionAppHandoffApprovalTest.php`
- Test: `tests/Feature/InspectionAppHandoffTest.php` (existing tests keep passing; two `workOrderWith` calls need an estimate)

**Interfaces:**
- Consumes: `ApprovalGate::decide()` (Task 2).
- Produces: `HandoffService::consider()` writes `status=held`/`held_reason` or `status=pending`/`rule`; `HandoffService::push()` refuses a held row; `HandoffService::sweep()` returns `['checked','queued','unchanged','held','released','read']`; `HandoffService::startedBeforeGoLive(WorkOrder): bool`; `HandoffService::release(InspectionAppHandoff $row, ApprovalDecision $decision): void`. `sweep()`'s `read` count stays 0 until Task 5.

- [ ] **Step 1: Write the failing tests**

Add to `InspectionAppHandoffApprovalTest` (imports: `App\Jobs\PushWorkOrderToInspectionApp`, `App\Services\InspectionApp\HandoffService`, `App\Models\ServiceStatus`, `Illuminate\Support\Facades\Queue`, `Illuminate\Support\Facades\Http`). Add a `setUp()` matching `InspectionAppHandoffTest`'s config block and `ServiceStatus` row; add `Queue::fake()` where noted.

```php
    private function service(): HandoffService
    {
        return app(HandoffService::class);
    }

    public function test_consider_sends_a_cheap_work_order_and_names_the_rule(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 120]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('pending', $row->status);
        $this->assertSame('under_threshold', $row->rule);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_consider_holds_an_expensive_work_order_with_the_reason_and_pushes_nothing(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->assertSame('held', $row->status);
        $this->assertNull($row->rule);
        $this->assertSame('Estimate $450.00 is over $300, and no owner approval is recorded', $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_push_refuses_a_held_row(): void
    {
        Http::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'x']);

        $this->service()->push($row->id);

        Http::assertNothingSent();
        $this->assertSame('held', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_a_held_row_is_decided_again_when_the_work_order_comes_back_through_consider(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $this->service()->consider($workOrder->id, 'vendor_assigned');

        $workOrder->forceFill(['approved_by' => 'J. Owner'])->save();
        $row = $this->service()->consider($workOrder->id, 'status_sync');

        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_recorded', $row->rule);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_sent_work_order_is_not_pulled_back_by_a_later_change(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 120]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'payload_hash' => 'abc'])->save();

        $workOrder->forceFill(['cost_estimate' => 900])->save();
        $again = $this->service()->consider($workOrder->id, 'status_sync');

        $this->assertSame('pending', $again->status);
        $this->assertSame('under_threshold', $again->rule);
    }

    public function test_the_sweep_releases_a_held_row_once_the_approval_is_on_record(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $this->service()->consider($workOrder->id, 'vendor_assigned');
        $workOrder->forceFill(['approved_date' => '2026-11-12'])->save();

        $result = $this->service()->sweep();

        $this->assertSame(1, $result['held']);
        $this->assertSame(1, $result['released']);
        $row = InspectionAppHandoff::query()->where('work_order_id', $workOrder->id)->sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_recorded', $row->rule);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_work_order_from_before_go_live_is_never_handed_off_the_first_time(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_since' => '2026-12-01']);
        $old = $this->workOrder(['cost_estimate' => 120, 'created_date' => '2026-11-20']);
        $new = $this->workOrder(['cost_estimate' => 120, 'created_date' => '2026-12-02']);
        $this->thmpOn($old);
        $this->thmpOn($new);

        $this->assertNull($this->service()->consider($old->id, 'vendor_assigned'));
        $this->assertSame('pending', $this->service()->consider($new->id, 'vendor_assigned')->status);
        $this->assertTrue($this->service()->startedBeforeGoLive($old));
        $this->assertFalse($this->service()->startedBeforeGoLive($new));
    }

    public function test_the_manual_push_by_number_skips_the_filter_and_says_so(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_1'], 201)]);
        $workOrder = $this->workOrder(['cost_estimate' => 900]);
        $this->thmpOn($workOrder);

        $row = $this->service()->pushNow((int) $workOrder->work_order_no);

        $this->assertSame('sent', $row->status);
        $this->assertSame('manual', $row->rule);
    }
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: FAIL — `rule` null on the first test, `held` never set, `startedBeforeGoLive` undefined.

- [ ] **Step 3: Change `HandoffService`**

Constructor: add `private ApprovalGate $gate` after `$rule`.

Replace the body of `consider()` from `$row = InspectionAppHandoff::query()->where(...)->first();` onward:

```php
        $row = InspectionAppHandoff::query()->where('work_order_id', $workOrder->id)->first();

        if (! $row && ! $this->rule->isOpen($workOrder)) {
            return null;
        }

        if (! $row && $this->startedBeforeGoLive($workOrder)) {
            return null;
        }

        // A work order the app has already been told about is updated, never
        // pulled back through the filter: a later change goes as an update.
        if ($row && $row->status !== InspectionAppHandoff::STATUS_HELD && $row->rule !== null) {
            $row->forceFill(['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => $reason])->save();
            PushWorkOrderToInspectionApp::dispatch($row->id)->afterCommit();

            return $row;
        }

        $row ??= InspectionAppHandoff::query()->create([
            'work_order_id' => $workOrder->id,
            'status' => InspectionAppHandoff::STATUS_HELD,
            'reason' => $reason,
        ]);
        $row->forceFill(['reason' => $reason])->save();

        $this->release($row, $this->gate->decide($workOrder));

        return $row;
    }

    /**
     * Apply a decision to a row: queue it under its rule, or hold it with
     * the reason. The one place a row moves from held to pending.
     */
    public function release(InspectionAppHandoff $row, ApprovalDecision $decision): void
    {
        if ($decision->send) {
            $row->forceFill([
                'status' => InspectionAppHandoff::STATUS_PENDING,
                'rule' => $decision->rule,
                'held_reason' => null,
            ])->save();
            PushWorkOrderToInspectionApp::dispatch($row->id)->afterCommit();

            return;
        }

        $row->forceFill([
            'status' => InspectionAppHandoff::STATUS_HELD,
            'rule' => null,
            'held_reason' => $decision->heldReason,
        ])->save();
    }

    /**
     * Go-live without a backfill: a work order created before
     * handoff_since is never handed off for the first time.
     */
    public function startedBeforeGoLive(WorkOrder $workOrder): bool
    {
        $since = trim((string) config('services.inspection_app.handoff_since', ''));

        if ($since === '') {
            return false;
        }

        $created = $workOrder->created_date ?? $workOrder->created_at;

        if ($created === null) {
            return false;
        }

        return Carbon::parse((string) $created)->lt(Carbon::parse($since)->startOfDay());
    }
```

(Add `use Illuminate\Support\Carbon;`.)

In `push()`, after the `$row` null check:

```php
        if ($row->status === InspectionAppHandoff::STATUS_HELD) {
            return;
        }
```

In `pushNow()`, change the `firstOrCreate` to set the rule, and force it on an existing row:

```php
        $row = InspectionAppHandoff::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id],
            ['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => 'manual', 'rule' => InspectionAppHandoff::RULE_MANUAL],
        );
        $row->forceFill(['status' => InspectionAppHandoff::STATUS_PENDING, 'reason' => 'manual', 'rule' => InspectionAppHandoff::RULE_MANUAL, 'held_reason' => null])->save();
```

In `sweep()`: the existing query must exclude held rows (its `orWhere` on sent/failed already does; the pending branch too). After the loop and before `return`, add the held pass and extend the return:

```php
        $held = $this->sweepHeld($dryRun);

        return ['checked' => $rows->count(), 'queued' => $queued, 'unchanged' => $unchanged] + $held;
    }

    /**
     * Held rows: decide each again, since an approval may have been entered
     * in PropertyWare. Task 5 adds the AI reading here.
     *
     * @return array{held: int, released: int, read: int}
     */
    private function sweepHeld(bool $dryRun): array
    {
        $rows = InspectionAppHandoff::query()
            ->where('status', InspectionAppHandoff::STATUS_HELD)
            ->orderBy('id')
            ->get();

        $released = 0;

        foreach ($rows as $row) {
            $workOrder = WorkOrder::withoutGlobalScopes()->with('vendors')->find($row->work_order_id);

            if (! $workOrder || ! $this->rule->qualifies($workOrder) || ! $this->rule->isOpen($workOrder)) {
                if (! $dryRun) {
                    $row->forceFill(['status' => InspectionAppHandoff::STATUS_SKIPPED, 'last_error' => 'No longer ours, or closed before it was ever sent.'])->save();
                }

                continue;
            }

            $decision = $this->gate->decide($workOrder);

            if ($decision->send) {
                $released++;

                if (! $dryRun) {
                    $this->release($row, $decision);
                }

                continue;
            }

            if (! $dryRun) {
                $row->forceFill(['held_reason' => $decision->heldReason])->save();
            }
        }

        return ['held' => $rows->count(), 'released' => $released, 'read' => 0];
    }
```

In `PushWorkOrdersToInspectionApp::handle()`, change the info line to:

```php
        $this->info(sprintf(
            '%d checked, %d %s, %d unchanged; %d held, %d read by AI, %d %s.',
            $result['checked'],
            $result['queued'],
            $dryRun ? 'would be queued' : 'queued',
            $result['unchanged'],
            $result['held'],
            $result['read'],
            $result['released'],
            $dryRun ? 'would be released' : 'released',
        ));
```

- [ ] **Step 4: Keep the existing hand-off tests honest**

In `tests/Feature/InspectionAppHandoffTest.php`, `workOrderWith()` creates work orders with no estimate, which the filter now holds. Make the helper's default a cheap one so the existing tests keep exercising the send path: change `WorkOrder::factory()->create($attributes)` to `WorkOrder::factory()->create($attributes + ['cost_estimate' => 150])`. Run the whole file: every one of its 18 tests must still pass. The one that asserts the sweep's info line (`test_the_sweep_dry_run_and_the_off_switch_queue_nothing`, if it checks output text) is updated to the new wording `; 0 held, 0 read by AI, 0 would be released.`

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter='InspectionAppHandoffApprovalTest|InspectionAppHandoffTest|InspectionAppHandoffTriggersTest'`
Expected: PASS. If a triggers test fails because its work order has no estimate, give that factory call `'cost_estimate' => 150` too.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InspectionApp/HandoffService.php app/Console/Commands/PushWorkOrdersToInspectionApp.php tests/Feature/InspectionAppHandoffApprovalTest.php tests/Feature/InspectionAppHandoffTest.php tests/Feature/InspectionAppHandoffTriggersTest.php
git commit -m "feat(handoff): the gate decides every first send -- a held row waits with its reason, the sweep frees it once the approval is on record, and nothing before the go-live date is backfilled"
```

---

### Task 4: `OwnerApprovalReader` — the AI reads notes and owner threads

**Files:**
- Create: `app/Ai/Agents/OwnerApprovalReaderAgent.php`
- Create: `app/Services/InspectionApp/OwnerApprovalReader.php`
- Test: `tests/Feature/OwnerApprovalReaderTest.php`

**Interfaces:**
- Consumes: `AiInsight::TYPE_OWNER_APPROVAL` (Task 1), `App\Services\AiSettings::ready()`, `App\Ai\Concerns\ConfigurableAiProvider`.
- Produces:
  - `OwnerApprovalReader::enabled(): bool`
  - `OwnerApprovalReader::minimumConfidence(): int`
  - `OwnerApprovalReader::text(WorkOrder): string` — the prompt body
  - `OwnerApprovalReader::hash(WorkOrder): string`
  - `OwnerApprovalReader::read(WorkOrder): ?AiInsight` — asks the AI, validates, stores; null when the AI could not be asked
  - `OwnerApprovalReader::preview(WorkOrder): ?array` — asks and validates, stores nothing (for the evaluation command, Task 7)
  - `OwnerApprovalReader::latestFor(WorkOrder): ?AiInsight`
  - `OwnerApprovalReader::isStale(WorkOrder, AiInsight): bool`
  - `OwnerApprovalReader::approves(AiInsight): bool`
  - `OwnerApprovalReader::summarize(AiInsight): array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, read_at: ?string, messages_read: int}`
  - `OwnerApprovalReader::describe(AiInsight): string`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/OwnerApprovalReaderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI reads a held work order's description, Notes tab, approval comment
 * and owner threads and says which problems the owner approved, quoting the
 * owner's words. A confident yes lets the hand-off send the work order.
 */
class OwnerApprovalReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.inspection_app.handoff_ai_reader' => true,
            'services.inspection_app.handoff_ai_confidence' => 80,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);
    }

    private function reader(): OwnerApprovalReader
    {
        return app(OwnerApprovalReader::class);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'work_order_no' => 43761,
            'description' => 'Hallway light out, front door sticks, lawn overgrown',
            'specific_location' => 'Upstairs',
            'cost_estimate' => 450,
            'approval_comments' => null,
        ]);
    }

    private function ownerText(WorkOrder $workOrder, string $message, bool $fromOwner, string $at): Conversation
    {
        return Conversation::query()->create([
            'message' => $message,
            'sender_number' => $fromOwner ? '+15125550100' : '+12813787957',
            'receiver_number' => $fromOwner ? '+12813787957' : '+15125550100',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => ! $fromOwner,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function note(WorkOrder $workOrder, string $subject, string $body): WorkOrderNotes
    {
        return WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => $subject,
            'body' => $body,
            'date' => '2026-11-09',
            'is_private' => false,
        ]);
    }

    private function ownerEmail(WorkOrder $workOrder, string $body, bool $fromOwner): OwnerEmailNotification
    {
        $owner = Owner::query()->create(['name' => 'Pat Owner', 'email' => 'pat@example.com', 'phone' => '5125550100']);

        return OwnerEmailNotification::query()->create([
            'work_order_id' => $workOrder->id,
            'owner_id' => $owner->id,
            'vendor_id' => null,
            'type' => 'vendor_assignment',
            'direction' => $fromOwner ? 'inbound' : 'outbound',
            'subject' => 'WO 43761',
            'body_html' => '<p>'.$body.'</p>',
            'body_text' => $body,
            'from_email' => $fromOwner ? 'pat@example.com' : 'woc@texasrenters.com',
            'to_email' => $fromOwner ? 'woc@texasrenters.com' : 'pat@example.com',
            'created_at' => '2026-11-11 09:00:00',
            'updated_at' => '2026-11-11 09:00:00',
        ]);
    }

    /**
     * @param  list<array{item: string, decision: string, evidence: ?string}>  $problems
     */
    private function fakeReading(array $problems, bool $approved, int $confidence, string $summary): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => $problems,
            'approved' => $approved,
            'confidence' => $confidence,
            'summary' => $summary,
        ]]);
    }

    public function test_the_text_carries_the_description_notes_approval_comment_and_owner_threads_in_order_and_nothing_else(): void
    {
        $workOrder = $this->workOrder();
        $workOrder->forceFill(['approval_comments' => 'Called owner 11/9'])->save();
        $this->note($workOrder, 'Tenant call', 'Tenant says the light has been out a week');
        $this->ownerText($workOrder, 'Estimate is $450 for the three items, ok to proceed?', false, '2026-11-10 13:00:00');
        $this->ownerText($workOrder, 'Go ahead with the light. Hold off on the door for now.', true, '2026-11-10 14:02:00');
        $this->ownerEmail($workOrder, 'Thanks, confirming the light only.', true);
        Conversation::query()->create([
            'message' => 'Can someone come today?',
            'sender_number' => '+15125550199',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
            'is_read' => false,
        ]);

        $text = $this->reader()->text($workOrder);

        $this->assertStringContainsString('WORK ORDER #43761', $text);
        $this->assertStringContainsString('Estimate: $450.00', $text);
        $this->assertStringContainsString('Hallway light out, front door sticks, lawn overgrown', $text);
        $this->assertStringContainsString('APPROVAL FIELDS', $text);
        $this->assertStringContainsString('Called owner 11/9', $text);
        $this->assertStringContainsString('NOTES TAB', $text);
        $this->assertStringContainsString('Tenant says the light has been out a week', $text);
        $this->assertStringContainsString('OWNER THREAD', $text);
        $this->assertStringContainsString('office (2026-11-10 13:00, SMS): "Estimate is $450', $text);
        $this->assertStringContainsString('owner (2026-11-10 14:02, SMS): "Go ahead with the light', $text);
        $this->assertStringContainsString('owner (2026-11-11 09:00, email): "Thanks, confirming the light only."', $text);
        $this->assertStringNotContainsString('Can someone come today?', $text);
        $this->assertLessThan(strpos($text, 'OWNER THREAD'), strpos($text, 'NOTES TAB'));
    }

    public function test_a_confident_reading_with_quotes_is_stored_and_approves(): void
    {
        $workOrder = $this->workOrder();
        $this->ownerText($workOrder, 'Go ahead with the light. Hold off on the door for now.', true, '2026-11-10 14:02:00');
        $this->fakeReading([
            ['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)'],
            ['item' => 'front door', 'decision' => 'declined', 'evidence' => 'Hold off on the door for now. — owner, 2026-11-10 14:02 (SMS)'],
            ['item' => 'lawn', 'decision' => 'not_mentioned', 'evidence' => null],
        ], true, 92, 'Owner approved the hallway light by text on 2026-11-10; declined the door; did not mention the lawn.');

        $insight = $this->reader()->read($workOrder);

        $this->assertNotNull($insight);
        $this->assertSame(AiInsight::TYPE_OWNER_APPROVAL, $insight->type);
        $this->assertSame(WorkOrder::class, $insight->subject_type);
        $this->assertSame($workOrder->id, (int) $insight->subject_id);
        $this->assertSame(92, (int) $insight->confidence);
        $this->assertTrue($this->reader()->approves($insight));
        $this->assertSame($this->reader()->hash($workOrder), $insight->data['text_hash']);
        $this->assertSame(1, $insight->data['messages_read']);
        $this->assertCount(3, $insight->data['problems']);
        $this->assertFalse($this->reader()->isStale($workOrder, $insight));
    }

    public function test_a_reading_under_the_bar_or_with_no_approved_item_does_not_approve(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'ok go — owner']], true, 60, 'Probably.');
        $this->assertFalse($this->reader()->approves($this->reader()->read($workOrder)));

        $this->fakeReading([['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]], false, 95, 'The owner has not answered.');
        $this->assertFalse($this->reader()->approves($this->reader()->read($workOrder)));
    }

    public function test_an_approval_without_the_owners_words_invalidates_the_reading(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => '']], true, 99, 'Approved.');

        $insight = $this->reader()->read($workOrder);

        $this->assertNotNull($insight);
        $this->assertFalse($insight->data['valid']);
        $this->assertFalse($this->reader()->approves($insight));
        $this->assertStringContainsString('without the owner', $insight->data['error']);
    }

    public function test_a_malformed_answer_is_a_failed_reading_never_a_guess(): void
    {
        $workOrder = $this->workOrder();
        OwnerApprovalReaderAgent::fake([['problems' => 'yes', 'approved' => true, 'confidence' => 99, 'summary' => '']]);

        $insight = $this->reader()->read($workOrder);

        $this->assertFalse($insight->data['valid']);
        $this->assertFalse($this->reader()->approves($insight));
    }

    public function test_the_same_text_is_never_sent_twice_and_a_new_message_makes_the_reading_stale(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]], false, 90, 'Nothing yet.');
        $first = $this->reader()->read($workOrder);
        $this->assertFalse($this->reader()->isStale($workOrder, $first));

        $this->ownerText($workOrder, 'Yes go ahead with all of it', true, '2026-11-12 08:00:00');
        $this->assertTrue($this->reader()->isStale($workOrder->fresh(), $first));

        $this->fakeReading([['item' => 'all', 'decision' => 'approved', 'evidence' => 'Yes go ahead with all of it — owner']], true, 95, 'Approved all.');
        $second = $this->reader()->read($workOrder->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($this->reader()->approves($second));
        $this->assertSame(1, AiInsight::query()->ofType(AiInsight::TYPE_OWNER_APPROVAL)->count());
    }

    public function test_the_agents_instructions_say_what_counts_and_that_the_later_message_wins(): void
    {
        $instructions = (string) (new OwnerApprovalReaderAgent)->instructions();

        foreach (['not yet', 'later message', 'only the owner', 'quote', 'not approved'] as $phrase) {
            $this->assertStringContainsStringIgnoringCase($phrase, $instructions);
        }
    }

    public function test_preview_reads_without_storing(): void
    {
        $workOrder = $this->workOrder();
        $this->fakeReading([['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'go — owner']], true, 90, 'Yes.');

        $reading = $this->reader()->preview($workOrder);

        $this->assertTrue($reading['approved']);
        $this->assertSame(0, AiInsight::query()->count());
    }
}
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=OwnerApprovalReaderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the agent**

`app/Ai/Agents/OwnerApprovalReaderAgent.php`:

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a work order's description, notes and the owner's messages and says
 * which of its problems the owner approved for repair, quoting the owner.
 * A confident approval sends the work order to the technicians' inspection
 * app with no coordinator step (the office, 2026-11-19), so the rules below
 * err on holding: "if you feel the owner approved it then go; if the
 * message is like not yet, or still unsure, do not send it."
 */
class OwnerApprovalReaderAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read a repair work order from a Texas property management company: its description, the Notes tab, the approval fields, and every message between the office and the property OWNER (email and SMS, oldest first; "office" is the property manager, "owner" is the owner).',
            'Answer one question: WHICH OF THE PROBLEMS ON THIS WORK ORDER HAS THE OWNER APPROVED FOR REPAIR? The owner often approves one item and not the others.',
            '',
            'Rules:',
            '- List every distinct problem the work order describes (e.g. "hallway light", "front door", "lawn"). For each, decide approved, declined or not_mentioned.',
            '- Approved means a clear go-ahead from the owner, in the owner\'s own words: "go ahead", "approved", "yes, fix the light", "do it".',
            '- Anything short of that is NOT approved: "not yet", "hold off", "let me think", "I\'ll get back to you"; a question back to the office with no owner answer after it; a condition the thread does not show met ("ok if under $400" with no estimate under it); silence.',
            '- Only the owner can approve. A tenant\'s yes, a vendor\'s yes, or the office writing "owner says ok" without the owner\'s own message is not approved. An approval of a different work order or an earlier, separate repair at the same property is not approved.',
            '- The LATER MESSAGE WINS: "go ahead" followed by "actually hold off" is not approved.',
            '- Every approved or declined decision MUST quote the owner\'s exact sentence as evidence, with who wrote it and when, copied from the thread. Never paraphrase. not_mentioned has null evidence.',
            '- When in doubt, say not approved. A wrong yes sends a crew to do work the owner has not agreed to pay for.',
            '',
            'problems: one entry per problem, with item, decision (approved | declined | not_mentioned) and evidence (the quote, or null).',
            'approved: true only when at least one problem is approved.',
            'confidence: 0-100, how sure you are of the whole reading.',
            'summary: one or two plain sentences for the technician, saying what the owner approved, declined and did not mention, with the date and channel of the approval.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'problems' => $schema->array()->items(
                $schema->object([
                    'item' => $schema->string()->required(),
                    'decision' => $schema->string()->required(),
                    'evidence' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'approved' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
```

If `->nullable()` is not a method on this JsonSchema string builder (check `BoardSummaryAgent` and `vendor/laravel/ai` for the string type's methods), use `$schema->string()->required()` and treat `""` as null in the reader; the reader below already does.

- [ ] **Step 4: Write the reader**

`app/Services/InspectionApp/OwnerApprovalReader.php`:

```php
<?php

namespace App\Services\InspectionApp;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\OwnerEmailNotification;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\AiSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The AI's reading of a held work order: which of its problems the owner
 * approved, with the owner's words quoted (docs/superpowers/specs/
 * 2026-11-19-work-order-handoff-approval-filter-design.md).
 *
 * Built the way TenantEasyFixReplyJudge is. One reading per work order,
 * stored as an AiInsight whose subject is the WorkOrder row, so a re-run
 * updates in place; the hash of the text it read is kept with it, so the
 * same text is never sent twice and a new message makes the reading stale.
 * A malformed answer, or an approval without the owner's quoted words, is
 * stored as an invalid reading: it never approves, and it never guesses.
 */
class OwnerApprovalReader
{
    public const LOG_NAME = 'owner_approval_reading';

    public const DECISIONS = ['approved', 'declined', 'not_mentioned'];

    private const MAX_MESSAGE = 1200;

    private const MAX_NOTE = 800;

    public function enabled(): bool
    {
        return (bool) config('services.inspection_app.handoff_ai_reader', true) && AiSettings::ready();
    }

    public function minimumConfidence(): int
    {
        return (int) config('services.inspection_app.handoff_ai_confidence', 80);
    }

    /**
     * Everything the AI reads, in the order the spec lists: the work order,
     * the approval fields, the Notes tab, then the owner's messages oldest
     * first. Only owner-party text: tenant and vendor threads are never here.
     */
    public function text(WorkOrder $workOrder): string
    {
        $estimate = app(ApprovalGate::class)->estimate($workOrder);
        $lines = [
            'WORK ORDER #'.($workOrder->work_order_no ?? $workOrder->id),
            'Estimate: '.($estimate === null ? 'none' : '$'.number_format($estimate, 2)),
            'Category: '.trim((string) $workOrder->category).' / '.trim((string) $workOrder->type),
            'Description: '.$this->excerpt($workOrder->description, 4000),
            'Specific location: '.($this->excerpt($workOrder->specific_location, 300) ?: 'n/a'),
            '',
            'APPROVAL FIELDS (from PropertyWare)',
            'Approved by: '.($this->excerpt($workOrder->approved_by, 120) ?: 'none'),
            'Approved date: '.($workOrder->approved_date ? Carbon::parse((string) $workOrder->approved_date)->toDateString() : 'none'),
            'Approval comment: '.($this->excerpt($workOrder->approval_comments, 2000) ?: 'none'),
            '',
            'NOTES TAB (oldest first)',
        ];

        $notes = $this->notes($workOrder);
        $lines[] = $notes->isEmpty() ? '    (no notes)' : null;
        foreach ($notes as $note) {
            $lines[] = sprintf('    %s%s: "%s"', $note['when'], $note['subject'] ? ' '.$note['subject'] : '', $note['body']);
        }

        $lines[] = '';
        $lines[] = 'OWNER THREAD (oldest first; "office" = the property manager, "owner" = the property owner)';
        $messages = $this->ownerMessages($workOrder);
        $lines[] = $messages->isEmpty() ? '    (no messages with the owner)' : null;
        foreach ($messages as $message) {
            $lines[] = sprintf('    %s (%s, %s): "%s"', $message['who'], $message['when'], $message['channel'], $message['text']);
        }

        return implode("\n", array_values(array_filter($lines, fn ($line) => $line !== null)));
    }

    public function hash(WorkOrder $workOrder): string
    {
        return hash('sha256', $this->text($workOrder));
    }

    public function read(WorkOrder $workOrder): ?AiInsight
    {
        $text = $this->text($workOrder);
        $reading = $this->ask($workOrder, $text);

        if ($reading === null) {
            return null;
        }

        $insight = AiInsight::query()->updateOrCreate(
            [
                'type' => AiInsight::TYPE_OWNER_APPROVAL,
                'subject_type' => WorkOrder::class,
                'subject_id' => $workOrder->id,
            ],
            [
                'work_order_id' => $workOrder->id,
                'status' => AiInsight::STATUS_OPEN,
                'confidence' => $reading['confidence'],
                'data' => $reading + [
                    'text_hash' => hash('sha256', $text),
                    'messages_read' => $this->ownerMessages($workOrder)->count(),
                    'model' => AiSettings::effectiveModel(),
                ],
                'generated_at' => now(),
            ],
        );

        $this->record($workOrder, $insight);

        return $insight;
    }

    /**
     * The reading alone, stored nowhere: for the evaluation command.
     *
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, error: ?string}|null
     */
    public function preview(WorkOrder $workOrder): ?array
    {
        return $this->ask($workOrder, $this->text($workOrder));
    }

    public function latestFor(WorkOrder $workOrder): ?AiInsight
    {
        return AiInsight::query()
            ->ofType(AiInsight::TYPE_OWNER_APPROVAL)
            ->where('subject_type', WorkOrder::class)
            ->where('subject_id', $workOrder->id)
            ->first();
    }

    public function isStale(WorkOrder $workOrder, AiInsight $insight): bool
    {
        return (string) data_get($insight->data, 'text_hash') !== $this->hash($workOrder);
    }

    public function approves(AiInsight $insight): bool
    {
        return (bool) data_get($insight->data, 'valid', false)
            && (bool) data_get($insight->data, 'approved', false)
            && (int) $insight->confidence >= $this->minimumConfidence();
    }

    /**
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, read_at: ?string, messages_read: int}
     */
    public static function summarize(AiInsight $insight): array
    {
        return [
            'approved' => (bool) data_get($insight->data, 'approved', false),
            'valid' => (bool) data_get($insight->data, 'valid', false),
            'confidence' => (int) $insight->confidence,
            'summary' => (string) data_get($insight->data, 'summary', ''),
            'problems' => array_values((array) data_get($insight->data, 'problems', [])),
            'read_at' => $insight->generated_at?->toJSON(),
            'messages_read' => (int) data_get($insight->data, 'messages_read', 0),
        ];
    }

    public static function describe(AiInsight $insight): string
    {
        $reading = self::summarize($insight);

        $verdict = match (true) {
            ! $reading['valid'] => 'reading invalid',
            $reading['approved'] => 'owner approved',
            default => 'no owner approval found',
        };

        return "{$verdict} ({$reading['confidence']}%): {$reading['summary']}";
    }

    /**
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, error: ?string}|null
     */
    private function ask(WorkOrder $workOrder, string $text): ?array
    {
        try {
            $response = (new OwnerApprovalReaderAgent)->prompt($text, timeout: 45);
        } catch (\Throwable $exception) {
            Log::warning('Owner approval reading failed; the work order will be read on the next sweep.', [
                'work_order_id' => $workOrder->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->validate(is_array($response) ? $response : (array) $response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{approved: bool, valid: bool, confidence: int, summary: string, problems: list<array{item: string, decision: string, evidence: ?string}>, error: ?string}
     */
    private function validate(array $response): array
    {
        $confidence = max(0, min(100, (int) data_get($response, 'confidence', 0)));
        $summary = Str::limit(trim((string) data_get($response, 'summary', '')), 600);
        $rawProblems = data_get($response, 'problems');
        $problems = [];
        $error = null;

        if (! is_array($rawProblems)) {
            $error = 'The answer carried no list of problems.';
        } else {
            foreach ($rawProblems as $problem) {
                $item = trim((string) data_get($problem, 'item', ''));
                $decision = trim((string) data_get($problem, 'decision', ''));
                $evidence = trim((string) data_get($problem, 'evidence', ''));

                if ($item === '' || ! in_array($decision, self::DECISIONS, true)) {
                    $error = 'A problem had no name or an unknown decision.';
                    break;
                }

                if ($decision !== 'not_mentioned' && $evidence === '') {
                    $error = "\"{$item}\" was marked {$decision} without the owner's words quoted.";
                    break;
                }

                $problems[] = ['item' => Str::limit($item, 120), 'decision' => $decision, 'evidence' => $evidence === '' ? null : Str::limit($evidence, 500)];
            }
        }

        $anyApproved = collect($problems)->contains(fn (array $problem): bool => $problem['decision'] === 'approved');
        $valid = $error === null && $problems !== [];

        return [
            'approved' => $valid && $anyApproved && (bool) data_get($response, 'approved', false),
            'valid' => $valid,
            'confidence' => $confidence,
            'summary' => $summary,
            'problems' => $problems,
            'error' => $error ?? ($problems === [] ? 'The answer listed no problems.' : null),
        ];
    }

    /**
     * @return Collection<int, array{when: string, subject: string, body: string}>
     */
    private function notes(WorkOrder $workOrder): Collection
    {
        return WorkOrderNotes::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrderNotes $note): array => [
                'when' => $this->when($note->date ?: $note->created_at, false),
                'subject' => $this->excerpt($note->subject, 120),
                'body' => $this->excerpt($note->body, self::MAX_NOTE),
            ])
            ->filter(fn (array $note): bool => $note['body'] !== '')
            ->values();
    }

    /**
     * The owner's SMS threads and emails on this work order, merged, oldest
     * first. is_read on work_order_conversations is the direction: true = the
     * office wrote it (ChatbotEventProcessor sets it to ! inbound).
     *
     * @return Collection<int, array{who: string, when: string, channel: string, text: string, at: Carbon}>
     */
    private function ownerMessages(WorkOrder $workOrder): Collection
    {
        $texts = Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->whereIn('conversation_type', ['owner', 'vendor_owner'])
            ->orderBy('id')
            ->get(['id', 'message', 'is_read', 'created_at'])
            ->map(fn (Conversation $message): array => [
                'who' => $message->is_read ? 'office' : 'owner',
                'when' => $this->when($message->created_at, true),
                'channel' => 'SMS',
                'text' => $this->excerpt($message->message, self::MAX_MESSAGE),
                'at' => Carbon::parse((string) $message->created_at),
            ]);

        $emails = OwnerEmailNotification::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get(['id', 'direction', 'body_text', 'created_at'])
            ->map(fn (OwnerEmailNotification $email): array => [
                'who' => $email->direction === 'inbound' ? 'owner' : 'office',
                'when' => $this->when($email->created_at, true),
                'channel' => 'email',
                'text' => $this->excerpt($email->body_text, self::MAX_MESSAGE),
                'at' => Carbon::parse((string) $email->created_at),
            ]);

        return $texts->concat($emails)
            ->filter(fn (array $message): bool => $message['text'] !== '')
            ->sortBy(fn (array $message): string => $message['at']->toDateTimeString())
            ->values();
    }

    private function when(mixed $value, bool $withTime): string
    {
        try {
            $moment = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $withTime ? $moment->format('Y-m-d H:i') : $moment->toDateString();
    }

    private function excerpt(?string $value, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $value)), $limit);
    }

    private function record(WorkOrder $workOrder, AiInsight $insight): void
    {
        try {
            activity(self::LOG_NAME)
                ->performedOn($workOrder)
                ->event(data_get($insight->data, 'approved') ? 'owner_approval_found' : 'owner_approval_not_found')
                ->withProperties([
                    'confidence' => (int) $insight->confidence,
                    'valid' => (bool) data_get($insight->data, 'valid'),
                    'messages_read' => (int) data_get($insight->data, 'messages_read'),
                ])
                ->log(Str::limit(self::describe($insight), 250));
        } catch (\Throwable $exception) {
            Log::warning('Recording the owner approval reading failed.', ['work_order_id' => $workOrder->id, 'error' => $exception->getMessage()]);
        }
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=OwnerApprovalReaderTest`
Expected: PASS (8 tests). If `Owner::create` needs more columns, look at `app/Models/Owner.php`'s `$guarded`/fillable and the `owners` migration and supply them in the test helper.

- [ ] **Step 6: Commit**

```bash
git add app/Ai/Agents/OwnerApprovalReaderAgent.php app/Services/InspectionApp/OwnerApprovalReader.php tests/Feature/OwnerApprovalReaderTest.php
git commit -m "feat(handoff): the AI reads a held work order's notes and owner threads and says which problems the owner approved, quoting the owner -- stored once per work order, never re-read for the same text"
```

---

### Task 5: Rule 3a — the reading releases the row; the job and the sweep

**Files:**
- Modify: `app/Services/InspectionApp/ApprovalGate.php`
- Modify: `app/Services/InspectionApp/HandoffService.php`
- Create: `app/Jobs/ReadOwnerApprovalForHandoff.php`
- Test: `tests/Feature/InspectionAppHandoffApprovalTest.php`

**Interfaces:**
- Consumes: `OwnerApprovalReader` (Task 4), `HandoffService::release()` (Task 3).
- Produces: `ApprovalGate::decide()` honours a reading (rule 3a, confirmation switch); `HandoffService::readAndRelease(int $handoffId): void`; `HandoffService::sweep()`'s `read` count; job `ReadOwnerApprovalForHandoff::dispatch(int $handoffId)`.

- [ ] **Step 1: Write the failing tests**

Add to `InspectionAppHandoffApprovalTest` (imports: `App\Jobs\ReadOwnerApprovalForHandoff`, `App\Ai\Agents\OwnerApprovalReaderAgent`, `App\Models\Conversation`). In `setUp()` add `'services.inspection_app.handoff_ai_reader' => true, 'ai.default' => 'openai', 'ai.providers.openai.key' => 'test-key'`.

```php
    private function approvedReading(int $confidence = 92): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'approved', 'evidence' => 'Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)']],
            'approved' => true,
            'confidence' => $confidence,
            'summary' => 'Owner approved the hallway light by text on 2026-11-10.',
        ]]);
    }

    private function noApprovalReading(): void
    {
        OwnerApprovalReaderAgent::fake([[
            'problems' => [['item' => 'hallway light', 'decision' => 'not_mentioned', 'evidence' => null]],
            'approved' => false,
            'confidence' => 90,
            'summary' => 'The owner has not answered.',
        ]]);
    }

    private function ownerSaid(WorkOrder $workOrder, string $message): void
    {
        Conversation::query()->create([
            'message' => $message,
            'sender_number' => '+15125550100',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => false,
        ]);
    }

    public function test_consider_queues_a_reading_for_a_held_work_order_and_the_reading_sends_it(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        Queue::assertPushed(ReadOwnerApprovalForHandoff::class, fn (ReadOwnerApprovalForHandoff $job) => $job->handoffId === $row->id);

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame('owner_approved_in_messages', $row->rule);
        $this->assertNotNull($row->ai_insight_id);
        $this->assertNull($row->held_reason);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_a_reading_that_finds_no_approval_keeps_the_row_held_and_says_what_it_read(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $this->ownerSaid($workOrder, 'How much is the door by itself?');
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->noApprovalReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('held', $row->status);
        $this->assertStringContainsString('no owner approval is recorded', $row->held_reason);
        $this->assertStringContainsString('AI read 1 owner message and found no approval (90%)', $row->held_reason);
        Queue::assertNotPushed(PushWorkOrderToInspectionApp::class);
    }

    public function test_a_reading_under_the_confidence_bar_does_not_send(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->approvedReading(70);
        $this->service()->readAndRelease($row->id);

        $this->assertSame('held', $row->fresh()->status);
    }

    public function test_the_confirmation_switch_holds_a_confident_reading_with_the_reading_in_the_reason(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_requires_confirmation' => true]);
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        $this->approvedReading();
        $this->service()->readAndRelease($row->id);

        $row->refresh();
        $this->assertSame('held', $row->status);
        $this->assertStringContainsString('Owner approved in messages (92%)', $row->held_reason);
        $this->assertStringContainsString('enter the approval in PropertyWare to send', $row->held_reason);
        $this->assertNotNull($row->ai_insight_id);
    }

    public function test_the_sweep_reads_held_rows_up_to_the_cap_and_never_the_same_text_twice(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_reads_per_sweep' => 2]);
        $rows = [];
        foreach ([1, 2, 3] as $n) {
            $workOrder = $this->workOrder(['cost_estimate' => 450, 'description' => "Problem {$n}"]);
            $this->thmpOn($workOrder);
            $rows[] = $this->service()->consider($workOrder->id, 'vendor_assigned');
        }
        $asked = 0;
        OwnerApprovalReaderAgent::fake(function () use (&$asked) {
            $asked++;

            return ['problems' => [['item' => 'x', 'decision' => 'not_mentioned', 'evidence' => null]], 'approved' => false, 'confidence' => 90, 'summary' => 'Nothing.'];
        });

        $first = $this->service()->sweep();
        $this->assertSame(2, $first['read']);
        $this->assertSame(2, $asked);

        $second = $this->service()->sweep();
        $this->assertSame(1, $second['read']);
        $this->assertSame(3, $asked);

        $third = $this->service()->sweep();
        $this->assertSame(0, $third['read']);
        $this->assertSame(3, $asked);
    }

    public function test_the_sweep_reads_again_only_when_the_owner_writes_again(): void
    {
        Queue::fake();
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $this->noApprovalReading();
        $this->service()->readAndRelease($row->id);

        $this->assertSame(0, $this->service()->sweep()['read']);

        $this->ownerSaid($workOrder, 'Yes, go ahead with the light.');
        $this->approvedReading();
        $result = $this->service()->sweep();

        $this->assertSame(1, $result['read']);
        $this->assertSame(1, $result['released']);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_the_reader_switched_off_leaves_held_rows_waiting_for_the_fields(): void
    {
        Queue::fake();
        config(['services.inspection_app.handoff_ai_reader' => false]);
        $workOrder = $this->workOrder(['cost_estimate' => 450]);
        $this->thmpOn($workOrder);

        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');

        Queue::assertNotPushed(ReadOwnerApprovalForHandoff::class);
        $this->assertSame(0, $this->service()->sweep()['read']);
        $this->assertSame('held', $row->fresh()->status);
    }
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: FAIL — `ReadOwnerApprovalForHandoff` not found; `readAndRelease` undefined.

- [ ] **Step 3: Teach the gate about the reading**

In `ApprovalGate`, add a constructor and replace the final `return ApprovalDecision::hold(...)` of `decide()`:

```php
    public function __construct(private OwnerApprovalReader $reader) {}
```

```php
        $reading ??= $this->reader->latestFor($workOrder);

        if ($reading !== null && $this->reader->approves($reading)) {
            if ((bool) config('services.inspection_app.handoff_ai_requires_confirmation', false)) {
                return ApprovalDecision::hold(
                    sprintf('Owner approved in messages (%d%%): %s — enter the approval in PropertyWare to send', (int) $reading->confidence, Str::limit((string) data_get($reading->data, 'summary'), 90)),
                    $estimate,
                    $threshold,
                    false,
                );
            }

            return ApprovalDecision::send(InspectionAppHandoff::RULE_OWNER_APPROVED_IN_MESSAGES, $estimate, $threshold);
        }

        $reason = $this->heldReason($estimate, $threshold);

        if ($reading !== null) {
            $count = (int) data_get($reading->data, 'messages_read', 0);
            $reason .= sprintf(
                ' · AI read %d owner message%s and found %s (%d%%)',
                $count,
                $count === 1 ? '' : 's',
                data_get($reading->data, 'valid') ? 'no approval' : 'nothing it could use',
                (int) $reading->confidence,
            );
        }

        $needsReading = $this->reader->enabled() && ($reading === null || $this->reader->isStale($workOrder, $reading));

        return ApprovalDecision::hold(Str::limit($reason, 200, ''), $estimate, $threshold, $needsReading);
```

(Add `use Illuminate\Support\Str;`.) Keep `$reading` optional in the signature; callers that already hold a fresh reading pass it to save a query.

- [ ] **Step 4: The job**

`app/Jobs/ReadOwnerApprovalForHandoff.php`:

```php
<?php

namespace App\Jobs;

use App\Services\InspectionApp\HandoffService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A work order was held over the threshold with no approval on record: ask
 * the AI to read the owner's messages now rather than at the next sweep, so
 * an approval that is already in the thread sends the crew within the
 * minute. The sweep is the safety net if this never runs.
 */
class ReadOwnerApprovalForHandoff implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly int $handoffId) {}

    public function handle(HandoffService $service): void
    {
        $service->readAndRelease($this->handoffId);
    }
}
```

- [ ] **Step 5: The service**

In `HandoffService`: constructor adds `private OwnerApprovalReader $reader`. In `release()`, after the held branch's `save()`:

```php
        if ($decision->needsReading) {
            ReadOwnerApprovalForHandoff::dispatch($row->id)->afterCommit();
        }
```

Add:

```php
    /**
     * Read one held row's owner messages and let it go if the reading is a
     * confident approval. Runs from the queued job and from the sweep.
     * Returns whether the AI was actually asked.
     */
    public function readAndRelease(int $handoffId): bool
    {
        $row = InspectionAppHandoff::query()->find($handoffId);

        if (! $row || $row->status !== InspectionAppHandoff::STATUS_HELD || ! $this->enabled()) {
            return false;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()->with('vendors')->find($row->work_order_id);

        if (! $workOrder || ! $this->rule->qualifies($workOrder)) {
            return false;
        }

        $reading = $this->reader->latestFor($workOrder);
        $asked = false;

        if ($this->reader->enabled() && ($reading === null || $this->reader->isStale($workOrder, $reading))) {
            $reading = $this->reader->read($workOrder) ?? $reading;
            $asked = true;
        }

        $decision = $this->gate->decide($workOrder, $reading);
        $row->forceFill(['ai_insight_id' => $reading?->id])->save();
        $this->releaseWithoutReading($row, $decision);

        return $asked;
    }
```

Make `release()` call `releaseWithoutReading()` for the row update and only add the dispatch of the reading job on top, so `readAndRelease()` never re-queues itself:

```php
    public function release(InspectionAppHandoff $row, ApprovalDecision $decision): void
    {
        $this->releaseWithoutReading($row, $decision);

        if (! $decision->send && $decision->needsReading) {
            ReadOwnerApprovalForHandoff::dispatch($row->id)->afterCommit();
        }
    }

    private function releaseWithoutReading(InspectionAppHandoff $row, ApprovalDecision $decision): void
    {
        // the pending / held forceFill blocks from Task 3, unchanged
    }
```

In `sweepHeld()`, replace the body of the loop after the qualifies check:

```php
            $reading = $this->reader->latestFor($workOrder);
            $stale = $this->reader->enabled() && ($reading === null || $this->reader->isStale($workOrder, $reading));

            if ($stale && $read < $cap) {
                $read++;

                if (! $dryRun) {
                    $reading = $this->reader->read($workOrder) ?? $reading;
                }
            }

            $decision = $this->gate->decide($workOrder, $reading);

            if (! $dryRun) {
                $row->forceFill(['ai_insight_id' => $reading?->id])->save();
            }

            if ($decision->send) {
                $released++;

                if (! $dryRun) {
                    $this->releaseWithoutReading($row, $decision);
                }

                continue;
            }

            if (! $dryRun) {
                $row->forceFill(['held_reason' => $decision->heldReason])->save();
            }
```

with `$cap = max(0, (int) config('services.inspection_app.handoff_ai_reads_per_sweep', 20));` and `$read = 0;` before the loop, and `'read' => $read` in the return.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --filter='InspectionAppHandoffApprovalTest|OwnerApprovalReaderTest|InspectionAppHandoffTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/InspectionApp/ApprovalGate.php app/Services/InspectionApp/HandoffService.php app/Jobs/ReadOwnerApprovalForHandoff.php tests/Feature/InspectionAppHandoffApprovalTest.php
git commit -m "feat(handoff): a confident AI reading of the owner's approval sends the work order on its own, as the office asked -- read at once when held, again only when the owner writes again, at most a few per sweep"
```

---

### Task 6: The envelope carries the estimate, the notes and the approval

**Files:**
- Modify: `app/Services/InspectionApp/HandoffPayload.php`
- Modify: `app/Services/InspectionApp/HandoffService.php` (`push()` and `sweep()` pass the row to `build()`)
- Test: `tests/Feature/InspectionAppHandoffApprovalTest.php`

**Interfaces:**
- Consumes: `ApprovalGate::estimate()`, `OwnerApprovalReader::summarize()`, row `rule`/`aiInsight`.
- Produces: `HandoffPayload::build(WorkOrder $workOrder, ?InspectionAppHandoff $row = null): array` with `work_order.cost_estimate: ?float`, top-level `notes: list<{subject: ?string, body: string, author: ?string, date: ?string, is_private: bool}>`, top-level `approval: {rule: ?string, threshold: float, estimate: ?float, recorded_by: ?string, recorded_at: ?string, ai_confidence: ?int, ai_read_at: ?string, summary: ?string, problems: list}`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_the_envelope_carries_the_estimate_the_notes_and_the_approval_block(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_9'], 201)]);
        $workOrder = $this->workOrder(['cost_estimate' => 450, 'work_order_no' => 43761]);
        $this->thmpOn($workOrder);
        WorkOrderNotes::query()->create(['work_order_id' => $workOrder->id, 'subject' => 'Tenant call', 'body' => 'Light out a week', 'date' => '2026-11-09', 'is_private' => false]);
        $this->ownerSaid($workOrder, 'Go ahead with the light.');
        $this->approvedReading();
        $row = $this->service()->consider($workOrder->id, 'vendor_assigned');
        $this->service()->readAndRelease($row->id);

        $this->service()->push($row->id);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $body = $request->data();

            return $body['work_order']['cost_estimate'] === 450.0
                && $body['notes'][0]['body'] === 'Light out a week'
                && $body['notes'][0]['subject'] === 'Tenant call'
                && $body['approval']['rule'] === 'owner_approved_in_messages'
                && $body['approval']['threshold'] === 300.0
                && $body['approval']['ai_confidence'] === 92
                && $body['approval']['problems'][0]['decision'] === 'approved'
                && str_contains($body['approval']['summary'], 'hallway light');
        });
    }

    public function test_the_envelope_names_the_recorded_approver_for_a_recorded_approval(): void
    {
        $workOrder = $this->workOrder(['cost_estimate' => 450, 'approved_by' => 'J. Owner', 'approved_date' => '2026-11-10']);
        $this->thmpOn($workOrder);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'pending', 'rule' => 'owner_approved_recorded']);

        $payload = app(\App\Services\InspectionApp\HandoffPayload::class)->build($workOrder->fresh(['vendors']), $row);

        $this->assertSame('owner_approved_recorded', $payload['approval']['rule']);
        $this->assertSame('J. Owner', $payload['approval']['recorded_by']);
        $this->assertStringStartsWith('2026-11-10', $payload['approval']['recorded_at']);
        $this->assertNull($payload['approval']['ai_confidence']);
        $this->assertSame([], $payload['approval']['problems']);
        $this->assertSame([], $payload['notes']);
    }
```

(Import `App\Models\WorkOrderNotes`.)

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=InspectionAppHandoffApprovalTest`
Expected: FAIL — undefined index `notes` / `approval`.

- [ ] **Step 3: Extend the payload**

In `HandoffPayload`: constructor becomes `public function __construct(private HandoffRule $rule, private ApprovalGate $gate) {}`. Signature `build(WorkOrder $workOrder, ?InspectionAppHandoff $row = null): array`. Inside `'work_order' => [...]`, after `'is_emergency'`:

```php
                'cost_estimate' => $this->gate->estimate($workOrder),
```

After the `'vendors' => ...` entry:

```php
            'notes' => $this->notes($workOrder),
            'approval' => $this->approval($workOrder, $row),
```

Add the methods:

```php
    /**
     * The Notes tab, oldest first. Private notes go too, flagged: the crew
     * is in-house, and the office asked for all the information.
     *
     * @return list<array{subject: ?string, body: string, author: ?string, date: ?string, is_private: bool}>
     */
    private function notes(WorkOrder $workOrder): array
    {
        return WorkOrderNotes::query()
            ->with('user')
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrderNotes $note): ?array => trim((string) $note->body) === '' ? null : [
                'subject' => $this->stringOrNull($note->subject),
                'body' => trim((string) $note->body),
                'author' => $this->stringOrNull($note->user?->name),
                'date' => $this->iso($note->date ?: $note->created_at),
                'is_private' => (bool) $note->is_private,
            ])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Which rule let it through and what stood behind it: the recorded
     * approver, or the AI's reading with the owner's quoted words.
     *
     * @return array<string, mixed>
     */
    private function approval(WorkOrder $workOrder, ?InspectionAppHandoff $row): array
    {
        $reading = $row?->aiInsight;
        $summary = $reading === null ? null : OwnerApprovalReader::summarize($reading);

        return [
            'rule' => $row?->rule,
            'threshold' => $this->gate->threshold(),
            'estimate' => $this->gate->estimate($workOrder),
            'recorded_by' => $this->gate->approvalRecorded($workOrder) ? $this->stringOrNull($workOrder->approved_by) : null,
            'recorded_at' => $this->gate->approvalRecorded($workOrder) ? $this->iso($workOrder->approved_date) : null,
            'ai_confidence' => $summary['confidence'] ?? null,
            'ai_read_at' => $summary['read_at'] ?? null,
            'summary' => $summary['summary'] ?? null,
            'problems' => $summary['problems'] ?? [],
        ];
    }
```

(Imports: `App\Models\InspectionAppHandoff`, `App\Models\WorkOrderNotes`.) If `WorkOrderNotes` has no `user()` relation, check the model; the `notes.user` eager load in `WorkOrderController` says it does.

In `HandoffService::push()`, `$payload = $this->payload->build($workOrder, $row);` and in `sweep()`'s unchanged check `$this->payload->build($workOrder, $row)`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --filter='InspectionAppHandoffApprovalTest|InspectionAppHandoffTest'`
Expected: PASS. `InspectionAppHandoffTest::test_push_sends_the_signed_work_order_and_records_the_answer` asserts the envelope shape; if it compares the whole body, add the three new keys to its expectation rather than loosening it.

- [ ] **Step 5: Commit**

```bash
git add app/Services/InspectionApp/HandoffPayload.php app/Services/InspectionApp/HandoffService.php tests/Feature/InspectionAppHandoffApprovalTest.php tests/Feature/InspectionAppHandoffTest.php
git commit -m "feat(handoff): the envelope carries the estimate, the Notes tab and the approval -- which rule let it through, the recorded approver, or the AI's reading with the owner's words"
```

---

### Task 7: Console: the sent-by-AI list and the evaluation over old work orders

**Files:**
- Create: `app/Console/Commands/ListInspectionAppHandoffs.php`
- Create: `app/Console/Commands/EvaluateOwnerApprovalReader.php`
- Test: `tests/Feature/InspectionAppHandoffCommandsTest.php`

**Interfaces:**
- Consumes: `OwnerApprovalReader::preview()`, `ApprovalGate`, `HandoffRule`.
- Produces: `inspection-app:handoffs {--rule=} {--status=} {--limit=50}`; `inspection-app:evaluate-approval-reader {--since=} {--limit=25} {--work-order=} {--json}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Ai\Agents\OwnerApprovalReaderAgent;
use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two console views of the approval filter: what the AI sent, after the
 * fact; and how the reader would have done on old work orders whose
 * approval is already known, before it is trusted with new ones.
 */
class InspectionAppHandoffCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.inspection_app.handoff_vendor_ids' => Vendor::THMP_PROPERTYWARE_ID,
            'services.inspection_app.handoff_approval_threshold' => 300,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);
        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function thmpWorkOrder(array $attributes): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create($attributes);
        $vendor = Vendor::query()->firstOrCreate(
            ['propertyware_id' => Vendor::THMP_PROPERTYWARE_ID],
            ['name' => Vendor::THMP_NAME, 'is_active' => true, 'user_id' => User::factory()->create()->id],
        );
        DB::table('work_order_vendors')->insert(['work_order_id' => $workOrder->id, 'vendor_id' => $vendor->id, 'created_at' => now(), 'updated_at' => now()]);

        return $workOrder;
    }

    public function test_the_list_shows_what_the_ai_sent_with_its_summary(): void
    {
        $workOrder = $this->thmpWorkOrder(['work_order_no' => 43761, 'cost_estimate' => 450]);
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 92, 'data' => ['approved' => true, 'valid' => true, 'summary' => 'Owner approved the hallway light.', 'problems' => []], 'generated_at' => now(),
        ]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'sent', 'rule' => 'owner_approved_in_messages', 'ai_insight_id' => $insight->id, 'sent_at' => now()]);
        $cheap = $this->thmpWorkOrder(['work_order_no' => 43762, 'cost_estimate' => 100]);
        InspectionAppHandoff::query()->create(['work_order_id' => $cheap->id, 'status' => 'sent', 'rule' => 'under_threshold', 'sent_at' => now()]);

        $this->artisan('inspection-app:handoffs', ['--rule' => 'owner_approved_in_messages'])
            ->expectsOutputToContain('43761')
            ->expectsOutputToContain('92%')
            ->expectsOutputToContain('Owner approved the hallway light.')
            ->doesntExpectOutputToContain('43762')
            ->assertSuccessful();
    }

    public function test_the_evaluation_scores_the_reader_against_approvals_already_on_record_and_stores_nothing(): void
    {
        $approved = $this->thmpWorkOrder(['work_order_no' => 100, 'cost_estimate' => 450, 'approved_by' => 'J. Owner', 'status' => 'Closed', 'created_date' => '2026-06-01']);
        $declined = $this->thmpWorkOrder(['work_order_no' => 101, 'cost_estimate' => 450, 'approved_by' => null, 'status' => 'Canceled By Tenant', 'created_date' => '2026-06-02']);
        $cheap = $this->thmpWorkOrder(['work_order_no' => 102, 'cost_estimate' => 90, 'status' => 'Closed', 'created_date' => '2026-06-03']);
        $answers = [
            100 => ['problems' => [['item' => 'light', 'decision' => 'approved', 'evidence' => 'go ahead — owner']], 'approved' => true, 'confidence' => 91, 'summary' => 'Approved.'],
            101 => ['problems' => [['item' => 'door', 'decision' => 'not_mentioned', 'evidence' => null]], 'approved' => false, 'confidence' => 88, 'summary' => 'No answer.'],
        ];
        OwnerApprovalReaderAgent::fake(function (string $prompt) use ($answers): array {
            preg_match('/WORK ORDER #(\d+)/', $prompt, $match);

            return $answers[(int) $match[1]];
        });

        $this->artisan('inspection-app:evaluate-approval-reader', ['--since' => '2026-01-01', '--limit' => 10])
            ->expectsOutputToContain('#100')
            ->expectsOutputToContain('#101')
            ->doesntExpectOutputToContain('#102')
            ->expectsOutputToContain('Agreed with the record: 2 of 2')
            ->expectsOutputToContain('Would have sent without a recorded approval: 0')
            ->expectsOutputToContain('Would have held an approved one: 0')
            ->assertSuccessful();

        $this->assertSame(0, AiInsight::query()->count());
        $this->assertSame(0, InspectionAppHandoff::query()->count());
    }
}
```

- [ ] **Step 2: Run them to make sure they fail**

Run: `php artisan test --filter=InspectionAppHandoffCommandsTest`
Expected: FAIL — command not found.

- [ ] **Step 3: The list command**

`app/Console/Commands/ListInspectionAppHandoffs.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\InspectionAppHandoff;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The hand-off outbox, as a table. --rule=owner_approved_in_messages is the
 * office's after-the-fact look at what the AI sent on its own reading, with
 * the reading beside each.
 */
class ListInspectionAppHandoffs extends Command
{
    protected $signature = 'inspection-app:handoffs
        {--rule= : Only rows let through by this rule (emergency, under_threshold, owner_approved_recorded, owner_approved_in_messages, manual)}
        {--status= : Only rows in this status (pending, sent, failed, skipped, held)}
        {--limit=50 : How many, newest first}';

    protected $description = 'List work orders handed (or held) for the technicians\' inspection app';

    public function handle(): int
    {
        $rows = InspectionAppHandoff::query()
            ->with(['workOrder', 'aiInsight'])
            ->when($this->option('rule'), fn ($query, $rule) => $query->where('rule', $rule))
            ->when($this->option('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['WO#', 'Status', 'Rule', 'Estimate', 'AI', 'Reading / held reason', 'When'],
            $rows->map(function (InspectionAppHandoff $row): array {
                $reading = $row->aiInsight === null ? null : OwnerApprovalReader::summarize($row->aiInsight);

                return [
                    $row->workOrder?->work_order_no ?? $row->work_order_id,
                    $row->status,
                    $row->rule ?? '—',
                    $row->workOrder?->cost_estimate === null ? '—' : '$'.number_format((float) $row->workOrder->cost_estimate, 2),
                    $reading === null ? '—' : $reading['confidence'].'%',
                    Str::limit($row->status === InspectionAppHandoff::STATUS_HELD ? (string) $row->held_reason : (string) ($reading['summary'] ?? ''), 90),
                    ($row->sent_at ?? $row->updated_at)?->format('Y-m-d H:i') ?? '',
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: The evaluation command**

`app/Console/Commands/EvaluateOwnerApprovalReader.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\InspectionApp\ApprovalGate;
use App\Services\InspectionApp\HandoffRule;
use App\Services\InspectionApp\OwnerApprovalReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * How would the AI have read the old work orders, whose approval is already
 * known? For each closed work order of our crews over the threshold, the
 * record says whether the owner approved (approved_by / approved_date). The
 * reader is asked the same question from the notes and messages, and the
 * two are compared. Nothing is stored and nothing is sent: this is the
 * office looking before it trusts the reader with new work orders.
 *
 * Costs one AI call per work order; --limit keeps that in hand.
 */
class EvaluateOwnerApprovalReader extends Command
{
    protected $signature = 'inspection-app:evaluate-approval-reader
        {--since=2026-01-01 : Work orders created on or after this date}
        {--limit=25 : How many work orders to read (one AI call each)}
        {--work-order= : Read this one work order by number instead}
        {--json : Print the readings as JSON for a spreadsheet}';

    protected $description = 'Score the AI owner-approval reader against old work orders whose approval is on record';

    public function handle(HandoffRule $rule, ApprovalGate $gate, OwnerApprovalReader $reader): int
    {
        if (! $reader->enabled()) {
            $this->error('The reader is off or no AI provider is configured (INSPECTION_APP_HANDOFF_AI_READER_ENABLED, AI settings).');

            return self::FAILURE;
        }

        $limit = min(500, max(1, (int) $this->option('limit')));
        $workOrders = WorkOrder::withoutGlobalScopes()
            ->with('vendors')
            ->when($this->option('work-order'), fn ($query, $number) => $query->where('work_order_no', (int) $number))
            ->when(! $this->option('work-order'), fn ($query) => $query
                ->whereIn('status', WorkOrder::CLOSED_STATUSES)
                ->where('created_date', '>=', $this->option('since'))
                ->where('is_emergency', '!=', true))
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get()
            ->filter(fn (WorkOrder $workOrder): bool => $rule->qualifies($workOrder))
            ->filter(fn (WorkOrder $workOrder): bool => $this->option('work-order') || ($gate->estimate($workOrder) ?? INF) >= $gate->threshold())
            ->take($limit)
            ->values();

        if ($workOrders->isEmpty()) {
            $this->warn('No closed work orders of our crews over the threshold since '.$this->option('since').'.');

            return self::SUCCESS;
        }

        $rows = [];
        $agreed = 0;
        $falseSends = 0;
        $misses = 0;
        $unreadable = 0;

        foreach ($workOrders as $workOrder) {
            $recorded = $gate->approvalRecorded($workOrder);
            $reading = $reader->preview($workOrder);

            if ($reading === null) {
                $unreadable++;
                $rows[] = ['#'.$workOrder->work_order_no, $recorded ? 'approved' : 'no approval', 'AI unavailable', '', ''];

                continue;
            }

            $aiSends = $reading['approved'] && $reading['confidence'] >= $reader->minimumConfidence();
            $agreed += (int) ($aiSends === $recorded);
            $falseSends += (int) ($aiSends && ! $recorded);
            $misses += (int) (! $aiSends && $recorded);

            $rows[] = [
                '#'.$workOrder->work_order_no,
                $recorded ? 'approved' : 'no approval',
                $aiSends ? 'would send' : 'would hold',
                $reading['confidence'].'%',
                Str::limit($reading['error'] ?? $reading['summary'], 80),
            ];

            if ($this->option('json')) {
                $this->line(json_encode(['work_order_no' => $workOrder->work_order_no, 'recorded' => $recorded] + $reading, JSON_UNESCAPED_UNICODE));
            }
        }

        if (! $this->option('json')) {
            $this->table(['WO', 'On record', 'AI', 'Confidence', 'Summary'], $rows);
        }

        $scored = count($rows) - $unreadable;
        $this->info(sprintf('Agreed with the record: %d of %d.', $agreed, $scored));
        $this->info(sprintf('Would have sent without a recorded approval: %d. (Check these: an approval that only lives in messages is the point, a wrong one is the risk.)', $falseSends));
        $this->info(sprintf('Would have held an approved one: %d.', $misses));

        if ($unreadable > 0) {
            $this->warn(sprintf('%d could not be read (AI error).', $unreadable));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=InspectionAppHandoffCommandsTest`
Expected: PASS (2 tests). Laravel auto-discovers commands in `app/Console/Commands`; nothing to register.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ListInspectionAppHandoffs.php app/Console/Commands/EvaluateOwnerApprovalReader.php tests/Feature/InspectionAppHandoffCommandsTest.php
git commit -m "feat(handoff): two console views -- what the AI sent on its own reading, and how it would have read the old work orders whose approval is on record, storing and sending nothing"
```

---

### Task 8: The work order page says why a work order waits, or how it went

**Files:**
- Modify: `app/Http/Controllers/WorkOrderRecommendationController.php`
- Create: `app/Services/InspectionApp/HandoffStatus.php`
- Modify: `resources/js/Pages/WorkOrder/Partials/Recommendation.vue`
- Test: `tests/Feature/InspectionAppHandoffStatusTest.php`

**Interfaces:**
- Produces: `HandoffStatus::for(WorkOrder): ?array{status: string, rule: ?string, held_reason: ?string, sent_at: ?string, reading: ?array}`; the recommendation JSON gains `inspection_app_handoff`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\InspectionAppHandoff;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use App\Services\InspectionApp\HandoffStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The work order page says where its hand-off stands, and why. */
class InspectionAppHandoffStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_held_row_is_reported_with_its_reason_and_reading(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $insight = AiInsight::query()->create([
            'work_order_id' => $workOrder->id, 'type' => AiInsight::TYPE_OWNER_APPROVAL, 'subject_type' => WorkOrder::class, 'subject_id' => $workOrder->id,
            'status' => 'open', 'confidence' => 90, 'data' => ['approved' => false, 'valid' => true, 'summary' => 'The owner has not answered.', 'problems' => [], 'messages_read' => 3], 'generated_at' => now(),
        ]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'Estimate $450.00 is over $300, and no owner approval is recorded', 'ai_insight_id' => $insight->id]);

        $status = app(HandoffStatus::class)->for($workOrder);

        $this->assertSame('held', $status['status']);
        $this->assertStringContainsString('over $300', $status['held_reason']);
        $this->assertSame(90, $status['reading']['confidence']);
        $this->assertSame('The owner has not answered.', $status['reading']['summary']);
    }

    public function test_no_row_means_null_and_a_sent_row_names_its_rule(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->assertNull(app(HandoffStatus::class)->for($workOrder));

        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'sent', 'rule' => 'under_threshold', 'sent_at' => now()]);
        $status = app(HandoffStatus::class)->for($workOrder->fresh());

        $this->assertSame('sent', $status['status']);
        $this->assertSame('under_threshold', $status['rule']);
        $this->assertNull($status['reading']);
    }

    public function test_the_recommendation_json_carries_the_handoff_status(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        WorkOrderRecommendation::query()->create(['work_order_id' => $workOrder->id, 'summary' => 'x', 'issue_type' => 'x', 'vendor_category' => 'x', 'reasoning' => 'x', 'keywords' => []]);
        InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'status' => 'held', 'held_reason' => 'No estimate on the work order, and no owner approval is recorded']);

        $this->actingAs($user)
            ->getJson(route('work_orders.recommendation.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('recommendation.inspection_app_handoff.status', 'held')
            ->assertJsonPath('recommendation.inspection_app_handoff.held_reason', 'No estimate on the work order, and no owner approval is recorded');
    }
}
```

Before writing, confirm the route name with `php artisan route:list --name=recommendation` and the `WorkOrderRecommendation` model's required columns with `grep -n "\$table->" database/migrations/*work_order_recommendations*`; adjust the test's `create([...])` to the real columns. If the controller is behind a role middleware, give the user that role the way `InspectionAppHandoffTriggersTest::setUp()` creates roles.

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=InspectionAppHandoffStatusTest`
Expected: FAIL — `HandoffStatus` not found.

- [ ] **Step 3: The status reader and the controller**

`app/Services/InspectionApp/HandoffStatus.php`:

```php
<?php

namespace App\Services\InspectionApp;

use App\Models\InspectionAppHandoff;
use App\Models\WorkOrder;

/**
 * Where a work order's hand-off to the inspection app stands, for the work
 * order page: held and why, or sent and under which rule, with the AI's
 * reading when there is one.
 */
class HandoffStatus
{
    /**
     * @return array{status: string, rule: ?string, held_reason: ?string, sent_at: ?string, reading: ?array}|null
     */
    public function for(WorkOrder $workOrder): ?array
    {
        $row = InspectionAppHandoff::query()->with('aiInsight')->where('work_order_id', $workOrder->id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'status' => $row->status,
            'rule' => $row->rule,
            'held_reason' => $row->held_reason,
            'sent_at' => $row->sent_at?->toJSON(),
            'reading' => $row->aiInsight === null ? null : OwnerApprovalReader::summarize($row->aiInsight),
        ];
    }
}
```

In `WorkOrderRecommendationController`: inject `private readonly HandoffStatus $handoffStatus` and add to `withPropertyHistory()`'s array:

```php
            'inspection_app_handoff' => $this->handoffStatus->for($workOrder),
```

- [ ] **Step 4: The Vue line**

In `resources/js/Pages/WorkOrder/Partials/Recommendation.vue`, next to the `easyFixReply` computed (around line 184):

```js
// Where the hand-off to the technicians' inspection app stands: held and
// why, or sent under which rule. Null when the work order is not ours.
const inspectionHandoff = computed(
    () => props.recommendation?.inspection_app_handoff ?? null,
);
const inspectionHandoffLine = computed(() => {
    const handoff = inspectionHandoff.value;
    if (!handoff) return null;
    const rules = {
        emergency: "sent as an emergency",
        under_threshold: "sent: under the approval threshold",
        owner_approved_recorded: "sent: owner approval on record",
        owner_approved_in_messages: "sent: owner approval read from messages by AI",
        manual: "sent by hand from the console",
    };
    if (handoff.status === "held") return `Waiting: ${handoff.held_reason}`;
    if (handoff.status === "sent") return `Inspection app: ${rules[handoff.rule] ?? "sent"}`;
    if (handoff.status === "pending") return `Inspection app: queued (${rules[handoff.rule] ?? "pending"})`;
    if (handoff.status === "failed") return "Inspection app refused it; see the outbox";
    return null;
});
```

And in the template, directly after the `<p v-if="easyFixReply" … data-easy-fix-reply>…</p>` block:

```vue
                        <p
                            v-if="inspectionHandoffLine"
                            class="text-sm leading-6 text-muted-foreground"
                            data-inspection-handoff
                        >
                            {{ inspectionHandoffLine }}
                            <span v-if="inspectionHandoff?.reading?.summary">
                                AI reading ({{ inspectionHandoff.reading.confidence }}%): {{ inspectionHandoff.reading.summary }}
                            </span>
                        </p>
```

Run `npm run build` once to confirm the component compiles.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=InspectionAppHandoffStatusTest` then `npm run build`
Expected: PASS; build succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InspectionApp/HandoffStatus.php app/Http/Controllers/WorkOrderRecommendationController.php resources/js/Pages/WorkOrder/Partials/Recommendation.vue tests/Feature/InspectionAppHandoffStatusTest.php
git commit -m "feat(handoff): the work order page says where its hand-off stands -- waiting and why, or sent and under which rule, with the AI's reading"
```

---

### Task 9: Inspection app: read the approval, notes and estimate into the visit

**Repo:** `C:\Users\ADMIN\texasrenters-inspection-app`, branch `feature/work-order-intake`.

**Files:**
- Modify: `backend/src/work-orders/work-order-handoff.schema.ts`
- Modify: `backend/src/work-orders/work-order-intake.service.ts` (`notesFor()`)
- Modify: `docs/integrations/TX_MAINTENANCE_WORK_ORDERS.md`
- Test: `backend/test/work-order-intake.spec.ts`

**Interfaces:**
- Consumes: the envelope from Task 6.
- Produces: `notesFor(handoff)` appends an estimate line, an "Owner approval" block and a "Notes" block.

- [ ] **Step 1: Write the failing test**

In `backend/test/work-order-intake.spec.ts`, next to the existing `notesFor` tests (around line 335), add:

```ts
  it('writes the estimate, the owner approval with its quotes, and the Notes tab into the visit notes (2026-11-19)', () => {
    const notes = notesFor({
      ...envelope(),
      work_order: { ...envelope().work_order, cost_estimate: 450 },
      notes: [
        { subject: 'Tenant call', body: 'Light out a week', author: 'Maria', date: '2026-11-09T00:00:00+00:00', is_private: false },
        { subject: null, body: 'Owner prefers mornings', author: null, date: null, is_private: true },
      ],
      approval: {
        rule: 'owner_approved_in_messages',
        threshold: 300,
        estimate: 450,
        recorded_by: null,
        recorded_at: null,
        ai_confidence: 92,
        ai_read_at: '2026-11-19T15:04:00+00:00',
        summary: 'Owner approved the hallway light by text on 2026-11-10; declined the door; did not mention the lawn.',
        problems: [
          { item: 'hallway light', decision: 'approved', evidence: 'Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)' },
          { item: 'front door', decision: 'declined', evidence: 'Hold off on the door for now. — owner, 2026-11-10 14:02 (SMS)' },
          { item: 'lawn', decision: 'not_mentioned', evidence: null },
        ],
      },
    });

    expect(notes).toContain('Estimate: $450.00');
    expect(notes).toContain('Owner approval: read from messages by AI (92%)');
    expect(notes).toContain('Owner approved the hallway light by text on 2026-11-10');
    expect(notes).toContain('- hallway light: APPROVED — "Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)"');
    expect(notes).toContain('- front door: declined — "Hold off on the door for now.');
    expect(notes).toContain('- lawn: not mentioned');
    expect(notes).toContain('Notes:');
    expect(notes).toContain('- 2026-11-09 Maria, Tenant call: Light out a week');
    expect(notes).toContain('- (private) Owner prefers mornings');
  });

  it('files an envelope without the new fields exactly as before', () => {
    expect(workOrderHandoffSchema.safeParse(envelope()).success).toBe(true);
    expect(notesFor(envelope())).not.toContain('Owner approval');
  });
```

`envelope()` is whatever fixture builder the spec file already uses for a valid envelope (look for the function that returns the documented sample); use its name.

- [ ] **Step 2: Run it to make sure it fails**

Run: `cd backend && npx vitest run test/work-order-intake.spec.ts` (or `npm run test:backend -- work-order-intake` from the root, whichever the repo's `package.json` wires)
Expected: FAIL — type error on `cost_estimate` / `notes` / `approval`, or the strings missing.

- [ ] **Step 3: Extend the schema**

In `work-order-handoff.schema.ts`, inside `work_order`, after `is_emergency`:

```ts
    cost_estimate: z.number().nonnegative().nullable().optional(),
```

After `vendors`:

```ts
  /** The Notes tab, oldest first. Optional: older senders do not carry it. */
  notes: z
    .array(
      z.object({
        subject: optionalText(200),
        body: z.string().trim().min(1).max(4000),
        author: optionalText(120),
        date: isoDate,
        is_private: z.boolean().default(false),
      }),
    )
    .max(50)
    .optional(),
  /**
   * Which of the sender's rules let the work order through, and what stood
   * behind it: the recorded approver, or the AI's reading with the owner's
   * quoted words. See TX_MAINTENANCE_WORK_ORDERS.md.
   */
  approval: z
    .object({
      rule: optionalText(40),
      threshold: z.number().nonnegative(),
      estimate: z.number().nonnegative().nullable(),
      recorded_by: optionalText(120),
      recorded_at: isoDate,
      ai_confidence: z.number().int().min(0).max(100).nullable(),
      ai_read_at: isoDate,
      summary: optionalText(1000),
      problems: z
        .array(
          z.object({
            item: text(120).min(1),
            decision: z.enum(['approved', 'declined', 'not_mentioned']),
            evidence: optionalText(500),
          }),
        )
        .max(30),
    })
    .nullable()
    .optional(),
```

- [ ] **Step 4: Extend `notesFor()`**

In `work-order-intake.service.ts`, after the `Specific location` line and before the links:

```ts
  if (typeof wo.cost_estimate === 'number') lines.push(`Estimate: $${wo.cost_estimate.toFixed(2)}`);
  const approval = handoff.approval;
  if (approval && approval.rule) {
    const how: Record<string, string> = {
      emergency: 'emergency, no approval needed',
      under_threshold: `under the $${approval.threshold.toFixed(0)} threshold, no approval needed`,
      owner_approved_recorded: `on record${approval.recorded_by ? ` by ${approval.recorded_by}` : ''}${approval.recorded_at ? ` on ${approval.recorded_at.slice(0, 10)}` : ''}`,
      owner_approved_in_messages: `read from messages by AI${approval.ai_confidence !== null ? ` (${approval.ai_confidence}%)` : ''}`,
      manual: 'sent by hand from the maintenance app',
    };
    lines.push('', `Owner approval: ${how[approval.rule] ?? approval.rule}`);
    if (approval.summary) lines.push(approval.summary);
    for (const problem of approval.problems) {
      const decision =
        problem.decision === 'approved' ? 'APPROVED' : problem.decision === 'declined' ? 'declined' : 'not mentioned';
      lines.push(`- ${problem.item}: ${decision}${problem.evidence ? ` — "${problem.evidence}"` : ''}`);
    }
  }
  if (handoff.notes?.length) {
    lines.push('', 'Notes:');
    for (const note of handoff.notes.slice(0, 20)) {
      const head = [note.date ? note.date.slice(0, 10) : null, note.author, note.subject].filter(Boolean).join(' ');
      const text = note.body.length > 400 ? `${note.body.slice(0, 397)}…` : note.body;
      lines.push(`- ${note.is_private ? '(private) ' : ''}${head ? `${head.replace(/ ([^ ]+)$/, ', $1')}: ` : ''}${text}`);
    }
  }
```

The `head.replace(...)` turns `2026-11-09 Maria Tenant call` into `2026-11-09 Maria, Tenant call`; when only one of the three parts is present it leaves it alone. If that reads too clever, build the head as `[date, author].filter(Boolean).join(' ')` and append `, ${subject}` when a subject exists; the test string is `2026-11-09 Maria, Tenant call`.

- [ ] **Step 5: The doc**

In `docs/integrations/TX_MAINTENANCE_WORK_ORDERS.md`, under "The envelope", add after the field table:

```markdown
Three optional fields arrived with the sender's approval filter (2026-11-19).
`work_order.cost_estimate` is the estimate the filter judged. `notes` is the
Notes tab, oldest first, private ones flagged. `approval` says which of the
sender's rules let the work order through -- `emergency`, `under_threshold`,
`owner_approved_recorded`, `owner_approved_in_messages`, `manual` -- with the
recorded approver, or the AI's reading: a confidence, a summary, and one entry
per problem on the work order (`approved`, `declined`, `not_mentioned`) with
the owner's quoted words. All three go into the visit's internal notes so the
technician reads what the owner agreed to and what to leave alone. An envelope
without them files as before.
```

- [ ] **Step 6: Run the tests, typecheck and lint**

Run: `cd backend && npx vitest run test/work-order-intake.spec.ts test/api-gateway.spec.ts && npx tsc --noEmit && npx eslint src/work-orders`
Expected: PASS, clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/work-orders/work-order-handoff.schema.ts backend/src/work-orders/work-order-intake.service.ts backend/test/work-order-intake.spec.ts docs/integrations/TX_MAINTENANCE_WORK_ORDERS.md
git commit -m "feat(work-orders): the visit's notes carry the estimate, the Notes tab and the owner's approval as the maintenance app read it -- which items the owner said yes to, in the owner's words"
```

---

### Task 10: Docs and the spec's last word

**Files:**
- Modify: `docs/superpowers/specs/2026-11-19-work-order-handoff-approval-filter-design.md`
- Modify: `app/Services/InspectionApp/HandoffRule.php` (docblock only)

- [ ] **Step 1: Bring the spec in line with what was built**

In the spec's rules paragraph after the table, replace "turns rule 3a into a held state with a confirm button for the office" with "turns rule 3a into a held state: the reading shows on the work order, and entering the approval in PropertyWare sends it through rule 2". In "What the office sees", change "A filter on the existing hand-off outbox view" to "`php artisan inspection-app:handoffs --rule=owner_approved_in_messages`, and the hand-off line on the work order's Recommendation tab". Add a short section before "Testing":

```markdown
## Before go-live

`php artisan inspection-app:evaluate-approval-reader --since=2026-01-01 --limit=50`
reads closed work orders of our crews over the threshold and compares the
AI's answer with the approval on record. It stores nothing and sends nothing.
Run it on the VPS against production data until the "would have sent without
a recorded approval" count is understood, then set
`INSPECTION_APP_HANDOFF_SINCE` to the go-live date so nothing older is
backfilled, and switch the hand-off on.
```

- [ ] **Step 2: Point `HandoffRule`'s docblock at the gate**

Append to the class docblock of `HandoffRule`: `Whether a qualifying work order may be *sent yet* is ApprovalGate's question, not this one's.`

- [ ] **Step 3: Run the whole hand-off suite one last time**

Run: `php artisan test --filter='InspectionAppHandoff|OwnerApprovalReader'`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-11-19-work-order-handoff-approval-filter-design.md app/Services/InspectionApp/HandoffRule.php
git commit -m "docs(handoff): the spec says what was built -- the console views in place of a queue, and the evaluation run before go-live"
```

---

## VPS runbook (after the branch is deployed)

Evaluate the reader on old work orders, storing and sending nothing:

```bash
cd /var/www/tx-maintenance            # the app's root on the VPS
php artisan inspection-app:evaluate-approval-reader --since=2026-01-01 --limit=50
php artisan inspection-app:evaluate-approval-reader --work-order=43761     # one, in detail
php artisan inspection-app:evaluate-approval-reader --since=2026-06-01 --limit=200 --json > approval-eval.jsonl
```

Go live fresh, no backfill:

```bash
# in .env
INSPECTION_APP_HANDOFF_SINCE=2026-12-01          # the go-live date
INSPECTION_APP_HANDOFF_ENABLED=true
INSPECTION_APP_URL=https://<inspection api host>
INSPECTION_APP_TOKEN=<key id>.<secret>
php artisan config:cache
php artisan inspection-app:push-work-orders --dry-run   # should report nothing older than the date
```

Watch what the AI sent:

```bash
php artisan inspection-app:handoffs --rule=owner_approved_in_messages
php artisan inspection-app:handoffs --status=held
```
