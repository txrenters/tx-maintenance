<?php

namespace Tests\Unit;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\BoardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The board predicates and filters behind the Summary popup.
 *
 * scopeForBoard() reproduces the board methods on WorkOrderController, which
 * build ServiceStatus queries with the work orders nested — a shape aggregates
 * cannot reuse. These tests pin the reproduction.
 */
class BoardSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function serviceStatus(string $name): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(
            ['name' => $name],
            ['description' => $name],
        );
    }

    /**
     * @return array<int, int>
     */
    private function idsOn(string $board): array
    {
        return WorkOrder::query()->forBoard($board)->pluck('id')->sort()->values()->all();
    }

    public function test_it_labels_every_known_board(): void
    {
        foreach (WorkOrder::BOARDS as $board) {
            $this->assertNotSame('', BoardSummaryService::label($board));
        }

        // An unrecognised key still reads sensibly rather than blank.
        $this->assertSame('Work Orders', BoardSummaryService::label('nonsense'));
    }

    public function test_the_main_board_holds_only_open_general_work(): void
    {
        $this->serviceStatus('New');

        $general = WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Closed', 'type' => 'Repair', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Turnover', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Biweekly Lawn Services', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Open', 'type' => 'Repair', 'category' => 'Move Out Inspection']);

        $this->assertSame([$general->id], $this->idsOn('main'));
    }

    public function test_the_inspections_board_matches_the_move_out_category(): void
    {
        $this->serviceStatus('New');

        $inspection = WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Move Out Inspection']);
        WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Plumbing']);
        WorkOrder::factory()->create(['status' => 'Closed', 'category' => 'Move Out Inspection']);

        $this->assertSame([$inspection->id], $this->idsOn('inspections'));
    }

    public function test_the_lawn_board_matches_either_the_category_or_the_type(): void
    {
        $this->serviceStatus('New');

        $byCategory = WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Lawn Service', 'type' => 'Repair']);
        $byType = WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Plumbing', 'type' => 'Biweekly Lawn Services']);
        WorkOrder::factory()->create(['status' => 'Open', 'category' => 'Plumbing', 'type' => 'Repair']);

        $this->assertSame(
            collect([$byCategory->id, $byType->id])->sort()->values()->all(),
            $this->idsOn('lawn_service'),
        );
    }

    public function test_the_closed_board_includes_tenant_cancellations(): void
    {
        $this->serviceStatus('New');

        $closed = WorkOrder::factory()->create(['status' => 'Closed']);
        $canceled = WorkOrder::factory()->create(['status' => 'Canceled By Tenant']);
        WorkOrder::factory()->create(['status' => 'Open']);

        $this->assertSame(
            collect([$closed->id, $canceled->id])->sort()->values()->all(),
            $this->idsOn('closed'),
        );
    }

    public function test_the_paid_board_needs_a_cost_and_a_recent_completion(): void
    {
        $this->serviceStatus('New');

        $paid = WorkOrder::factory()->create([
            'total_cost' => 250,
            'completed_date' => now()->subDays(3),
        ]);

        // Completed too long ago for the 30-day window.
        WorkOrder::factory()->create([
            'total_cost' => 250,
            'completed_date' => now()->subDays(45),
        ]);

        // Completed but never billed.
        WorkOrder::factory()->create([
            'total_cost' => 0,
            'completed_date' => now()->subDays(3),
        ]);

        // Billed but never completed.
        WorkOrder::factory()->create([
            'total_cost' => 250,
            'completed_date' => null,
        ]);

        $this->assertSame([$paid->id], $this->idsOn('paid'));
    }

    public function test_an_unknown_board_key_does_not_silently_return_everything(): void
    {
        $this->serviceStatus('New');

        WorkOrder::factory()->create(['status' => 'Open']);
        WorkOrder::factory()->create(['status' => 'Closed']);

        // The scope leaves the query untouched rather than falling through to
        // the main board's predicate; the controller rejects the key first.
        $this->assertCount(2, $this->idsOn('nonsense'));
    }

    public function test_the_search_filter_matches_a_work_order_number_exactly(): void
    {
        $this->serviceStatus('New');

        $exact = WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 4321]);
        // Would match a LIKE '%4321%' but must not match the board's exact rule.
        WorkOrder::factory()->create(['status' => 'Open', 'work_order_no' => 14321]);

        $ids = WorkOrder::query()
            ->forBoard('main')
            ->boardFilters(['search' => '4321'])
            ->pluck('id')
            ->all();

        $this->assertSame([$exact->id], $ids);
    }

    public function test_the_date_filter_needs_both_ends_to_apply(): void
    {
        $this->serviceStatus('New');

        $inRange = WorkOrder::factory()->create(['status' => 'Open', 'created_date' => now()->subDays(2)]);
        $outOfRange = WorkOrder::factory()->create(['status' => 'Open', 'created_date' => now()->subDays(20)]);

        $bounded = WorkOrder::query()
            ->forBoard('main')
            ->boardFilters([
                'start_date' => now()->subDays(5)->toDateString(),
                'end_date' => now()->toDateString(),
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$inRange->id], $bounded);

        // A lone start_date is ignored, matching the boards.
        $halfOpen = WorkOrder::query()
            ->forBoard('main')
            ->boardFilters(['start_date' => now()->subDays(5)->toDateString()])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$inRange->id, $outOfRange->id])->sort()->values()->all(),
            $halfOpen,
        );
    }

    public function test_the_emergency_filter_separates_undecided_work_orders(): void
    {
        $this->serviceStatus('New');

        $emergency = WorkOrder::factory()->create(['status' => 'Open', 'is_emergency' => true]);
        $routine = WorkOrder::factory()->create(['status' => 'Open', 'is_emergency' => false]);
        $undecided = WorkOrder::factory()->create(['status' => 'Open', 'is_emergency' => null]);

        $filtered = fn (string $value) => WorkOrder::query()
            ->forBoard('main')
            ->boardFilters(['emergency' => $value])
            ->pluck('id')
            ->all();

        $this->assertSame([$emergency->id], $filtered('emergency'));
        $this->assertSame([$routine->id], $filtered('non_emergency'));
        $this->assertSame([$undecided->id], $filtered('needs_review'));
    }
}
