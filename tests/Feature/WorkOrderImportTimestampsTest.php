<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The scheduled import (import:work-orders, every ten minutes) used to stamp
 * created_at and updated_at on every row it touched, changed or not. Nothing
 * read updated_at until the HVAC board's "moved since I last looked" counter,
 * which then flagged every open HVAC work order after each run (2026-09-22).
 * A re-import must leave an unchanged row's timestamps alone and only bump
 * updated_at when a field really moved.
 */
class WorkOrderImportTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_RUN = '2026-09-22 14:30:00';

    private const SECOND_RUN = '2026-09-22 14:40:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Minimal SOAP payload for import:work-orders — building keys are read
     * unguarded, and the Service Status custom field must resolve to a real
     * service_status row (the column is a non-nullable foreign key).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapWorkOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 777001,
            'number' => 4321,
            'building' => ['portfolio' => 'Portfolio A', 'abbreviation' => 'BLDG1', 'ID' => 999001],
            'category' => 'HVAC',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'AC blowing warm air.',
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
        ], $overrides);
    }

    /**
     * One PropertyWare stand-in for the whole test, answering each run with
     * the next payload: the Artisan kernel resolves the command once, so a
     * mock swapped in between runs would never reach it.
     *
     * @param  array<string, mixed>  ...$payloads
     */
    private function prepareSoapImports(array ...$payloads): void
    {
        Role::findOrCreate('woc', 'web');

        foreach (['New', 'Scheduled'] as $name) {
            if (! ServiceStatus::query()->where('name', $name)->exists()) {
                ServiceStatus::query()->create(['name' => $name, 'description' => $name]);
            }
        }

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrders')->andReturn(...array_map(fn (array $payload): array => [$payload], $payloads));
        $this->app->instance(PropertyWareService::class, $mock);
    }

    private function importAt(string $at): WorkOrder
    {
        Carbon::setTestNow(Carbon::parse($at));

        $this->artisan('import:work-orders')->assertExitCode(0);

        return WorkOrder::query()->where('propertyware_id', 777001)->firstOrFail();
    }

    public function test_reimporting_an_unchanged_work_order_leaves_its_timestamps_alone(): void
    {
        Queue::fake();
        $this->prepareSoapImports($this->soapWorkOrderPayload(), $this->soapWorkOrderPayload());

        $first = $this->importAt(self::FIRST_RUN);
        $this->assertTrue($first->created_at->equalTo(Carbon::parse(self::FIRST_RUN)));
        $this->assertTrue($first->updated_at->equalTo(Carbon::parse(self::FIRST_RUN)));

        // Any attribute the second run finds dirty is a value the import
        // normalises differently from what it stored, and would re-stamp the
        // row every ten minutes. (Eloquent fires `saving` before it checks
        // for changes, so an empty set is the clean case.)
        $dirty = &$this->dirtyOnSave();

        $second = $this->importAt(self::SECOND_RUN);

        $this->assertSame([], array_filter($dirty), 'A re-import of an unchanged payload dirtied: '.json_encode($dirty));
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertTrue($second->created_at->equalTo(Carbon::parse(self::FIRST_RUN)), 'created_at was rewritten by a re-import');
        $this->assertTrue($second->updated_at->equalTo(Carbon::parse(self::FIRST_RUN)), 'updated_at moved although nothing changed');
    }

    public function test_reimporting_a_changed_work_order_still_bumps_updated_at_and_says_what_moved(): void
    {
        Queue::fake();
        $this->prepareSoapImports(
            $this->soapWorkOrderPayload(),
            $this->soapWorkOrderPayload([
                'customFields' => [
                    ['fieldName' => 'Service Status', 'value' => 'Scheduled'],
                ],
            ]),
        );

        $this->importAt(self::FIRST_RUN);
        $dirty = &$this->dirtyOnSave();

        $second = $this->importAt(self::SECOND_RUN);

        $context = json_encode(['dirty' => $dirty, 'updated_at' => (string) $second->updated_at, 'summary' => $second->last_change_summary]);
        $this->assertTrue($second->created_at->equalTo(Carbon::parse(self::FIRST_RUN)), $context);
        $this->assertTrue($second->updated_at->equalTo(Carbon::parse(self::SECOND_RUN)), $context);
        // The status name comes from WorkOrder's per-process cache, which an
        // earlier test in the same run may have filled with other ids; the
        // fact pinned here is that the status change was recorded as one.
        $this->assertStringStartsWith('moved to ', (string) $second->last_change_summary, $context);
    }

    /**
     * The REST status sync (update:work-orders-status, every fifteen minutes)
     * writes the same fields through Eloquent's update(); a payload that
     * matches the row must not move updated_at either.
     */
    public function test_the_rest_status_sync_leaves_an_unchanged_work_order_alone(): void
    {
        Queue::fake();
        Role::findOrCreate('woc', 'web');

        Carbon::setTestNow(Carbon::parse(self::FIRST_RUN));
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 555001,
            'status' => 'Open',
            'description' => 'AC blowing warm air.',
            'category' => 'HVAC ',
            'priority' => 'Medium',
            'is_approved' => false,
            'total_cost' => 150,
            'cost_estimate' => null,
            'scheduled_end_date' => null,
        ]);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrdersViaRestAPI')->andReturn([[
            'id' => 555001,
            'status' => 'Open',
            'description' => 'AC blowing warm air.',
            'category' => 'HVAC ',
            'priority' => 'Medium',
            'approved' => false,
            'actualCost' => 150.0,
        ]]);
        $this->app->instance(PropertyWareService::class, $mock);

        $dirty = &$this->dirtyOnSave();
        Carbon::setTestNow(Carbon::parse(self::SECOND_RUN));

        $this->artisan('update:work-orders-status')->assertExitCode(0);

        $this->assertSame([], array_filter($dirty), 'The REST sync dirtied an unchanged work order: '.json_encode($dirty));
        $this->assertTrue($workOrder->fresh()->updated_at->equalTo(Carbon::parse(self::FIRST_RUN)), 'updated_at moved although nothing changed');
    }

    /**
     * Collects the dirty attributes of every WorkOrder save from here on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function &dirtyOnSave(): array
    {
        $dirty = [];
        WorkOrder::saving(function (WorkOrder $workOrder) use (&$dirty): void {
            $dirty[] = $workOrder->getDirty();
        });

        return $dirty;
    }
}
