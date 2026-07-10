<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepeatIssueDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function newServiceStatus(): ServiceStatus
    {
        return ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);
    }

    /**
     * A completed plumbing job at the same building within the window.
     */
    private function priorPlumbingJob(int $buildingId, CarbonInterface $completedDate): WorkOrder
    {
        return WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => $buildingId,
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'description' => 'Kitchen sink was clogged, cleared the drain.',
            'status' => 'Closed',
            'completed_date' => $completedDate,
        ]);
    }

    private function generateFor(WorkOrder $workOrder)
    {
        return $this->actingAs(User::factory()->create())
            ->post(route('work_orders.recommendation.generate', $workOrder));
    }

    public function test_repeat_issue_is_flagged_when_prior_matching_work_exists_at_same_property(): void
    {
        $buildingId = 7777;
        $this->priorPlumbingJob($buildingId, now()->subMonths(2));

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => $buildingId,
            'description' => 'Kitchen sink is clogged and the drain is backing up again.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $fresh = $workOrder->fresh();
        $this->assertEquals(1, $fresh->is_repeat_issue, 'A prior matching job at the same property should flag a repeat.');
        $this->assertEquals(1, $fresh->repeat_count, 'One prior matching job means a repeat count of one.');
    }

    public function test_a_first_time_issue_is_not_a_repeat(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => 7777,
            'description' => 'Kitchen sink is clogged and the drain is backing up.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $fresh = $workOrder->fresh();
        $this->assertEquals(0, $fresh->is_repeat_issue, 'With no prior history, the work order is not a repeat.');
        $this->assertEquals(0, $fresh->repeat_count);
    }

    public function test_prior_work_at_a_different_property_is_not_a_repeat(): void
    {
        // Same issue type, but a different building — must not count as a repeat
        // "at this property" even though cross-site history is used for vendors.
        $this->priorPlumbingJob(8888, now()->subMonths(1));

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => 7777,
            'description' => 'Kitchen sink is clogged and the drain is backing up.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $this->assertEquals(0, $workOrder->fresh()->is_repeat_issue, 'History at another building is not a repeat at this property.');
    }

    public function test_prior_work_outside_the_window_is_not_a_repeat(): void
    {
        $buildingId = 7777;
        $this->priorPlumbingJob($buildingId, now()->subMonths(15));

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => $buildingId,
            'description' => 'Kitchen sink is clogged and the drain is backing up.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $this->assertEquals(0, $workOrder->fresh()->is_repeat_issue, 'A job older than the lookback window does not make a repeat.');
    }

    public function test_a_different_issue_type_at_the_same_property_is_not_a_repeat(): void
    {
        // A prior electrical job at the same building should not make a new
        // plumbing issue a "repeat" — repeats are per issue type.
        WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => 7777,
            'type' => 'Electrical',
            'category' => 'Electrical',
            'description' => 'Breaker keeps tripping in the garage.',
            'status' => 'Closed',
            'completed_date' => now()->subMonths(2),
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => 7777,
            'description' => 'Kitchen sink is clogged and the drain is backing up.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $this->assertEquals(0, $workOrder->fresh()->is_repeat_issue, 'A different issue type at the same property is not a repeat.');
    }

    public function test_multiple_prior_jobs_increment_the_repeat_count(): void
    {
        $buildingId = 7777;
        $this->priorPlumbingJob($buildingId, now()->subMonths(2));
        $this->priorPlumbingJob($buildingId, now()->subMonths(5));

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => $buildingId,
            'description' => 'Kitchen sink is clogged and the drain is backing up again.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $fresh = $workOrder->fresh();
        $this->assertEquals(1, $fresh->is_repeat_issue);
        $this->assertEquals(2, $fresh->repeat_count, 'Two prior matching jobs should count as two.');
    }

    public function test_a_work_order_without_a_building_is_never_a_repeat(): void
    {
        // building_id is the property key; without it we cannot claim a repeat.
        $this->priorPlumbingJob(7777, now()->subMonths(2));

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $this->newServiceStatus()->id,
            'building_id' => null,
            'description' => 'Kitchen sink is clogged and the drain is backing up.',
            'is_emergency' => null,
        ]);

        $this->generateFor($workOrder)->assertOk();

        $this->assertEquals(0, $workOrder->fresh()->is_repeat_issue);
    }
}
