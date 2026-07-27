<?php

namespace Database\Seeders;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use Illuminate\Database\Seeder;

/**
 * Local-only helper: seed mock work orders stuck in the emergency
 * "Needs review" state so the confirm/override buttons on the
 * Recommendation tab can be tested by hand.
 *
 * The AI assessment is faked with a confidence BELOW the auto-apply
 * threshold (75), so the work order stays unclassified and the amber
 * review block with the Emergency / Non-emergency buttons renders.
 * These work orders have no PropertyWare id, so nothing real is touched.
 *
 * NOTE: do not click "Refresh" on the Recommendation tab for these —
 * that re-runs the real AI and replaces the mock assessment.
 */
class DemoEmergencyReviewSeeder extends Seeder
{
    public function run(): void
    {
        $serviceStatus = ServiceStatus::query()->firstWhere('name', 'New')
            ?? ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        $mocks = [
            [
                'work_order_no' => 99007,
                'description' => 'Tenant says the AC stopped working yesterday and the house feels hot. No mention of anyone with health issues.',
                'category' => 'HVAC',
                'assessment' => [
                    'is_emergency' => true,
                    'emergency_category' => 'HVAC',
                    'emergency_confidence' => 60,
                    'emergency_reason' => 'Complete loss of A/C reported, but the tenant did not state the indoor temperature or a health risk, so this may not meet the emergency bar.',
                ],
            ],
            [
                'work_order_no' => 99008,
                'description' => 'Water spot on the ceiling of the upstairs bedroom, tenant is not sure if it is still growing.',
                'category' => 'Roof Leak',
                'assessment' => [
                    'is_emergency' => false,
                    'emergency_category' => null,
                    'emergency_confidence' => 55,
                    'emergency_reason' => 'A ceiling water spot could be a minor past leak, but the tenant is unsure whether it is active, which would change the answer.',
                ],
            ],
        ];

        foreach ($mocks as $mock) {
            $workOrder = WorkOrder::query()->updateOrCreate(
                ['work_order_no' => $mock['work_order_no']],
                [
                    'description' => $mock['description'],
                    'category' => $mock['category'],
                    'location' => 'DEMO | Review Test',
                    'status' => 'Open',
                    'type' => 'Service Request',
                    'service_status_id' => $serviceStatus->id,
                    'is_emergency' => null,
                    'created_date' => now(),
                ]
            );

            WorkOrderRecommendation::query()->updateOrCreate(
                ['work_order_id' => $workOrder->id],
                [
                    'status' => 'generated',
                    'source' => 'heuristic',
                    'issue_type' => $mock['category'],
                    'summary' => $mock['description'],
                    'confidence' => 70,
                    'needs_human_review' => true,
                    'is_emergency' => $mock['assessment']['is_emergency'],
                    'emergency_category' => $mock['assessment']['emergency_category'],
                    'emergency_confidence' => $mock['assessment']['emergency_confidence'],
                    'emergency_reason' => $mock['assessment']['emergency_reason'],
                    'emergency_auto_applied' => false,
                    'keywords' => [],
                    'generated_at' => now(),
                ]
            );

            $this->command?->info("Seeded needs-review work order #{$mock['work_order_no']} (AI suggests: ".($mock['assessment']['is_emergency'] ? 'Emergency' : 'Non-emergency').', confidence '.$mock['assessment']['emergency_confidence'].').');
        }
    }
}
