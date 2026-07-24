<?php

namespace Database\Seeders;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use Illuminate\Database\Seeder;

/**
 * Local-only helper: seed a repeat-issue scenario so the "Repeat" badge on
 * the board card and the "Nth time at this property" card on the Recommendation
 * tab can be checked by hand.
 *
 * Two prior COMPLETED plumbing jobs at the same demo building, plus one current
 * OPEN plumbing job. The current work order is flagged is_repeat_issue with a
 * repeat_count of 2 (so it reads as the "3rd time"). None of these have a
 * PropertyWare id, so nothing real is ever touched.
 *
 * The prior jobs are real rows at the same building_id, so clicking "Refresh"
 * on the Recommendation tab re-detects the repeat legitimately (the heuristic
 * classifies all three as Plumbing) rather than wiping the demo.
 */
class DemoRepeatIssueSeeder extends Seeder
{
    private const DEMO_BUILDING_ID = 990001;

    public function run(): void
    {
        $serviceStatus = ServiceStatus::query()->firstWhere('name', 'New')
            ?? ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        // Two prior completed plumbing jobs at the same building.
        $priorOne = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => 99020],
            [
                'description' => 'Kitchen sink clogged, cleared the drain line.',
                'category' => 'Plumbing',
                'type' => 'Plumbing',
                'location' => 'DEMO | 100 Repeat Ln',
                'building_id' => self::DEMO_BUILDING_ID,
                'status' => 'Closed',
                'service_status_id' => $serviceStatus->id,
                'closing_comments' => 'Snaked the kitchen drain, flowing normally.',
                'completed_date' => now()->subMonths(2),
                'created_date' => now()->subMonths(2)->subDays(3),
            ]
        );

        $priorTwo = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => 99021],
            [
                'description' => 'Sink backing up again in the kitchen.',
                'category' => 'Plumbing',
                'type' => 'Plumbing',
                'location' => 'DEMO | 100 Repeat Ln',
                'building_id' => self::DEMO_BUILDING_ID,
                'status' => 'Closed',
                'service_status_id' => $serviceStatus->id,
                'closing_comments' => 'Cleared partial clog under the sink.',
                'completed_date' => now()->subMonths(5),
                'created_date' => now()->subMonths(5)->subDays(2),
            ]
        );

        // BEFORE the feature: the same third-time clog, but nothing declares it
        // a repeat. is_repeat_issue is null, so no board badge and no repeat card
        // render — the only hint is the "Similar Work Orders" list, which a human
        // had to notice and interpret themselves.
        $before = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => 99023],
            [
                'description' => 'Kitchen sink is clogged and the drain is backing up again.',
                'category' => 'Plumbing',
                'type' => 'Plumbing',
                'location' => 'DEMO | BEFORE (feature off)',
                'building_id' => self::DEMO_BUILDING_ID,
                'status' => 'Open',
                'service_status_id' => $serviceStatus->id,
                'is_emergency' => false,
                'is_repeat_issue' => null,
                'repeat_count' => null,
                'created_date' => now(),
            ]
        );

        // AFTER the feature: the exact same clog, now auto-flagged as the 3rd
        // occurrence — a board badge and an "Nth time at this property" card.
        $current = WorkOrder::query()->updateOrCreate(
            ['work_order_no' => 99022],
            [
                'description' => 'Kitchen sink is clogged and the drain is backing up again.',
                'category' => 'Plumbing',
                'type' => 'Plumbing',
                'location' => 'DEMO | AFTER (feature on)',
                'building_id' => self::DEMO_BUILDING_ID,
                'status' => 'Open',
                'service_status_id' => $serviceStatus->id,
                'is_emergency' => false,
                'is_repeat_issue' => true,
                'repeat_count' => 2,
                'created_date' => now(),
            ]
        );

        // Both cards get the same "Similar Work Orders" history (that existed
        // before this feature); only the AFTER card also declares the repeat.
        $matchedWorkOrders = [
            [
                'id' => $priorOne->id,
                'work_order_no' => $priorOne->work_order_no,
                'description' => $priorOne->description,
                'completed_date' => $priorOne->completed_date?->toDateString(),
                'vendor' => null,
                'closing_comments' => $priorOne->closing_comments,
            ],
            [
                'id' => $priorTwo->id,
                'work_order_no' => $priorTwo->work_order_no,
                'description' => $priorTwo->description,
                'completed_date' => $priorTwo->completed_date?->toDateString(),
                'vendor' => null,
                'closing_comments' => $priorTwo->closing_comments,
            ],
        ];

        WorkOrderRecommendation::query()->updateOrCreate(
            ['work_order_id' => $before->id],
            [
                'status' => 'generated',
                'source' => 'heuristic',
                'issue_type' => 'Plumbing',
                'summary' => 'Kitchen sink clog.',
                'confidence' => 80,
                'needs_human_review' => false,
                'is_emergency' => false,
                'emergency_category' => null,
                'emergency_confidence' => 30,
                'emergency_reason' => 'A clogged sink is an urgent maintenance issue but not an emergency.',
                'emergency_auto_applied' => true,
                'keywords' => ['sink', 'drain', 'clog'],
                'matched_work_orders' => $matchedWorkOrders,
                'generated_at' => now(),
            ]
        );

        WorkOrderRecommendation::query()->updateOrCreate(
            ['work_order_id' => $current->id],
            [
                'status' => 'generated',
                'source' => 'heuristic',
                'issue_type' => 'Plumbing',
                'summary' => 'Recurring kitchen sink clog at this property — third occurrence.',
                'confidence' => 80,
                'needs_human_review' => false,
                'is_emergency' => false,
                'emergency_category' => null,
                'emergency_confidence' => 30,
                'emergency_reason' => 'A clogged sink is an urgent maintenance issue but not an emergency.',
                'emergency_auto_applied' => true,
                'keywords' => ['sink', 'drain', 'clog'],
                'matched_work_orders' => [
                    [
                        'id' => $priorOne->id,
                        'work_order_no' => $priorOne->work_order_no,
                        'description' => $priorOne->description,
                        'completed_date' => $priorOne->completed_date?->toDateString(),
                        'vendor' => null,
                        'closing_comments' => $priorOne->closing_comments,
                    ],
                    [
                        'id' => $priorTwo->id,
                        'work_order_no' => $priorTwo->work_order_no,
                        'description' => $priorTwo->description,
                        'completed_date' => $priorTwo->completed_date?->toDateString(),
                        'vendor' => null,
                        'closing_comments' => $priorTwo->closing_comments,
                    ],
                ],
                'generated_at' => now(),
            ]
        );

        $this->command?->info('Seeded repeat-issue demo: work order #99022 (3rd plumbing clog at DEMO building), with priors #99020 and #99021.');
    }
}
