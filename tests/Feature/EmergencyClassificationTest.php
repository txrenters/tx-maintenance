<?php

namespace Tests\Feature;

use App\Ai\EmergencyCriteria;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmergencyClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function newServiceStatus(): ServiceStatus
    {
        return ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);
    }

    public function test_emergency_signals_auto_apply_the_emergency_flag_to_an_unclassified_work_order(): void
    {
        $user = User::factory()->create();
        $serviceStatus = $this->newServiceStatus();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Tenant reports a strong gas smell in the kitchen, suspected gas leak near the stove.',
            'is_emergency' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.is_emergency', true)
            ->assertJsonPath('recommendation.emergency_category', 'Gas');

        $this->assertDatabaseHas('work_order_recommendations', [
            'work_order_id' => $workOrder->id,
            'is_emergency' => true,
            'emergency_category' => 'Gas',
        ]);

        $this->assertEquals(1, $workOrder->fresh()->is_emergency, 'High-confidence emergency should be auto-applied to the work order.');
    }

    public function test_manual_emergency_classification_is_never_overwritten(): void
    {
        $user = User::factory()->create();
        $serviceStatus = $this->newServiceStatus();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Suspected gas leak in the garage.',
            'is_emergency' => false,
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.is_emergency', true);

        $this->assertEquals(0, $workOrder->fresh()->is_emergency, 'A staff classification must survive AI re-classification.');
    }

    public function test_non_emergency_text_leaves_the_work_order_unclassified(): void
    {
        $user = User::factory()->create();
        $serviceStatus = $this->newServiceStatus();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Dripping faucet in the hall bathroom, tenant asks for repair when convenient.',
            'is_emergency' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.is_emergency', false);

        $this->assertNull($workOrder->fresh()->is_emergency, 'Low-confidence non-emergency should be left for human review.');
    }

    public function test_ambiguous_hvac_signal_is_recorded_but_not_auto_applied(): void
    {
        $user = User::factory()->create();
        $serviceStatus = $this->newServiceStatus();

        // Loss of A/C is only an emergency in health-risk temperatures, which
        // keyword matching cannot judge, so its confidence sits below the
        // auto-apply threshold.
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Tenant says there is no ac since yesterday.',
            'is_emergency' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.is_emergency', true)
            ->assertJsonPath('recommendation.emergency_category', 'HVAC');

        $this->assertNull($workOrder->fresh()->is_emergency, 'Ambiguous emergency signals must go to human review, not auto-apply.');
    }

    public function test_auto_apply_regenerates_pending_tasks_like_the_manual_toggle(): void
    {
        $user = User::factory()->create();
        $serviceStatus = $this->newServiceStatus();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Sewage backup flooding the downstairs bathroom.',
            'is_emergency' => null,
        ]);

        $pendingTask = WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => 'pending',
        ]);
        $completedTask = WorkOrderTask::factory()->completed()->create([
            'work_order_id' => $workOrder->id,
        ]);

        $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder))
            ->assertOk();

        $this->assertEquals(1, $workOrder->fresh()->is_emergency);
        $this->assertSoftDeleted('work_order_tasks', ['id' => $pendingTask->id]);
        $this->assertNotSoftDeleted('work_order_tasks', ['id' => $completedTask->id]);
    }

    /**
     * Regression guard for the AI-down fallback: every emergency example from
     * the operations criteria (Chana, July 2026) must be caught by the keyword
     * heuristic, and none of the documented non-emergencies may false-positive.
     */
    #[DataProvider('chanaEmergencyExamples')]
    public function test_heuristic_catches_documented_emergency_examples(string $text, string $expectedCategory): void
    {
        $result = EmergencyCriteria::scan(strtolower($text));

        $this->assertTrue($result['is_emergency'], "Should flag as emergency: {$text}");
        $this->assertSame($expectedCategory, $result['category'], "Wrong category for: {$text}");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function chanaEmergencyExamples(): array
    {
        return [
            'active water leak' => ['There is an active water leak causing damage to the ceiling', 'Water'],
            'burst water line' => ['A water line burst in the wall', 'Water'],
            'overflowing toilet' => ['The toilet is overflowing and will not stop', 'Water'],
            'flooding' => ['The kitchen is flooding from a plumbing failure', 'Water'],
            'sewer backup' => ['Sewer backup inside the property, sewage everywhere', 'Water'],
            'burning smell panel' => ['Burning smell coming from the electrical panel', 'Electrical'],
            'sparking outlet' => ['The outlet is sparking', 'Electrical'],
            'no power' => ['Complete power outage, no power at all in the house', 'Electrical'],
            'no ac' => ['No AC at all since yesterday, it is very hot', 'HVAC'],
            'no heat' => ['No heat and it is freezing outside', 'HVAC'],
            'refrigerant leak' => ['There is a refrigerant leak', 'HVAC'],
            'active fire' => ['The stove caught fire', 'Fire & Smoke'],
            'smoke inside' => ['Smoke inside the property', 'Fire & Smoke'],
            'gas odor' => ['Strong gas odor in the kitchen', 'Gas'],
            'suspected gas leak' => ['Suspected gas leak near the meter', 'Gas'],
            'cannot lock door' => ['The front door is broken and cannot lock', 'Security'],
            'broken window' => ['A broken window, someone could get in', 'Security'],
            'break in' => ['There was a break in overnight', 'Security'],
            'ceiling collapse' => ['The ceiling collapsed in the bedroom', 'Structural'],
            'tree fell' => ['A large tree fell on the structure', 'Structural'],
            'carbon monoxide alarm' => ['The carbon monoxide alarm is going off', 'Health & Safety'],
            'no working toilet' => ['The only toilet is not working in a single bathroom home', 'Health & Safety'],
        ];
    }

    #[DataProvider('chanaNonEmergencyExamples')]
    public function test_heuristic_does_not_flag_documented_non_emergencies(string $text): void
    {
        $result = EmergencyCriteria::scan(strtolower($text));

        $this->assertFalse($result['is_emergency'], "Should NOT flag as emergency: {$text}");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function chanaNonEmergencyExamples(): array
    {
        return [
            'dripping faucet' => ['The bathroom faucet is dripping'],
            'running toilet' => ['The toilet keeps running'],
            'garbage disposal' => ['Garbage disposal not working'],
            'one appliance' => ['The dishwasher stopped working'],
            'one sink clogged' => ['The kitchen sink is clogged'],
            'minor roof leak dry weather' => ['Minor roof leak noticed during dry weather'],
            'pest sighting' => ['Saw a few roaches in the kitchen'],
            'ac not cooling to preference' => ['AC is working but not cooling to my preference'],
            'loss of hot water' => ['No hot water'],
            'cosmetic' => ['The paint is peeling in the hallway'],
        ];
    }
}
