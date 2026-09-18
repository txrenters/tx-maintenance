<?php

namespace Tests\Feature;

use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use App\Services\PropertyWareService;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A repeat work order recommends the vendor who handled the same issue at the
 * same building before — as a SUGGESTION only. Nothing here may attach a
 * vendor, email one, or push an assignment to PropertyWare: assigning stays a
 * human action.
 */
class RepeatVendorSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Generating a recommendation must never reach PropertyWare. Any call
        // here would mean an assignment is being pushed out.
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->never();

        Notification::fake();
    }

    private function makeVendor(string $pwId, string $name): Vendor
    {
        return $this->makeTypedVendor($pwId, $name, 'Plumbing', true);
    }

    private function makeTypedVendor(string $pwId, string $name, string $type, bool $active): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => $name,
            'vendor_type' => $type,
            'is_active' => $active,
            'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A repeat plumbing issue at building 10 whose prior job was done by $vendor,
     * plus the current unassigned work order at the same building.
     *
     * @return array{0: Vendor, 1: WorkOrder}
     */
    private function repeatScenario(): array
    {
        $vendor = $this->makeVendor('V-900', 'Reliable Plumbing');
        $this->completedJob(10, 'Plumbing', $vendor, 1);

        return [$vendor, $this->currentPlumbingWorkOrder(10)];
    }

    private function completedJob(int $buildingId, string $type, Vendor $vendor, int $monthsAgo): void
    {
        $job = WorkOrder::factory()->create([
            'service_status_id' => $this->serviceStatus()->id,
            'description' => $type.' job at this building.',
            'closing_comments' => 'Completed.',
            'type' => $type,
            'category' => $type,
            'completed_date' => now()->subMonths($monthsAgo),
            'building_id' => $buildingId,
            'location' => 'Austin',
            'status' => 'Closed',
        ]);
        $job->vendors()->attach($vendor->id);
    }

    private function currentPlumbingWorkOrder(int $buildingId): WorkOrder
    {
        return WorkOrder::factory()->create([
            'service_status_id' => $this->serviceStatus()->id,
            'description' => 'Kitchen sink drain is backing up and the faucet is leaking under the sink.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'building_id' => $buildingId,
            'location' => 'Austin',
            'is_emergency' => null,
        ]);
    }

    private function serviceStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstWhere('name', 'New')
            ?? ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    /**
     * Intake of a brand new work order.
     */
    private function generate(WorkOrder $workOrder): void
    {
        (new GenerateWorkOrderRecommendationJob($workOrder->id))
            ->handle(app(WorkOrderRecommendationService::class));
    }

    private function recommendationFor(WorkOrder $workOrder): ?WorkOrderRecommendation
    {
        return WorkOrderRecommendation::query()
            ->where('work_order_id', $workOrder->id)
            ->first();
    }

    /**
     * The whole point of the change: a repeat is suggested, never assigned.
     */
    public function test_a_repeat_suggests_the_prior_vendor_without_assigning_or_notifying(): void
    {
        [$vendor, $current] = $this->repeatScenario();

        $this->generate($current);

        $recommendation = $this->recommendationFor($current);
        $this->assertSame($vendor->id, $recommendation->recommended_vendor_id);
        $this->assertSame('repeat_issue', $recommendation->vendor_source);
        $this->assertStringContainsString('Repeat issue at this building', $recommendation->reasoning);

        // Suggested only: no link row, no vendor email.
        $this->assertDatabaseCount('work_order_vendors', 1); // the prior job's own link
        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_the_repeat_badge_is_still_applied_to_the_work_order(): void
    {
        [, $current] = $this->repeatScenario();

        $this->generate($current);

        $this->assertTrue((bool) $current->fresh()->is_repeat_issue);
        $this->assertSame(1, (int) $current->fresh()->repeat_count);
    }

    public function test_a_work_order_that_already_has_a_vendor_gets_no_repeat_suggestion(): void
    {
        [, $current] = $this->repeatScenario();

        // A coordinator already picked a different vendor — leave their choice be.
        $other = $this->makeVendor('V-901', 'Other Plumbing');
        $current->vendors()->attach($other->id);

        $this->generate($current);

        $this->assertNotSame('repeat_issue', $this->recommendationFor($current)->vendor_source);
        Notification::assertNothingSent();
    }

    public function test_a_closed_work_order_gets_no_repeat_suggestion(): void
    {
        [, $current] = $this->repeatScenario();
        $current->update(['status' => 'Closed', 'completed_date' => now()->subDay()]);

        $this->generate($current);

        $this->assertNotSame('repeat_issue', $this->recommendationFor($current)->vendor_source);
    }

    /**
     * The old fresh-start age cap existed only because generating could email a
     * vendor. Suggesting is inert, so a backlog work order may be suggested for
     * too — and still must not assign or notify anyone.
     */
    public function test_a_backlog_work_order_is_suggested_for_but_never_assigned(): void
    {
        [$vendor, $current] = $this->repeatScenario();
        $current->update(['created_at' => now()->subMonths(2)]);

        $this->generate($current);

        $this->assertSame($vendor->id, $this->recommendationFor($current)->recommended_vendor_id);
        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_it_suggests_the_same_issue_vendor_not_a_more_recent_different_issue_vendor(): void
    {
        // Same-issue plumbing job (older) with the right vendor; a more RECENT
        // HVAC job at the same building with a different active vendor.
        $plumber = $this->makeTypedVendor('V-910', 'Correct Plumbing', 'Plumbing', true);
        $hvac = $this->makeTypedVendor('V-911', 'Wrong HVAC', 'HVAC', true);
        $this->completedJob(10, 'Plumbing', $plumber, 6);
        $this->completedJob(10, 'HVAC', $hvac, 1);

        $current = $this->currentPlumbingWorkOrder(10);
        $this->generate($current);

        $recommendation = $this->recommendationFor($current);
        $this->assertSame($plumber->id, $recommendation->recommended_vendor_id);
        $this->assertSame('repeat_issue', $recommendation->vendor_source);
        Notification::assertNothingSent();
    }

    public function test_it_stays_hands_off_when_the_same_issue_prior_vendor_is_inactive(): void
    {
        // The plumbing vendor who handled it before is now inactive; an active
        // HVAC vendor also worked this building. Neither is a repeat suggestion.
        $inactivePlumber = $this->makeTypedVendor('V-920', 'Gone Plumbing', 'Plumbing', false);
        $hvac = $this->makeTypedVendor('V-921', 'Wrong HVAC', 'HVAC', true);
        $this->completedJob(10, 'Plumbing', $inactivePlumber, 3);
        $this->completedJob(10, 'HVAC', $hvac, 1);

        $current = $this->currentPlumbingWorkOrder(10);
        $this->generate($current);

        // Still flagged a repeat, but no repeat-sourced suggestion.
        $this->assertTrue((bool) $current->fresh()->is_repeat_issue);
        $this->assertNotSame('repeat_issue', $this->recommendationFor($current)->vendor_source);
        Notification::assertNothingSent();
    }

    public function test_a_non_repeat_is_never_suggested_from_the_repeat_path(): void
    {
        // No prior history at all — a first-time issue, so no "same vendor" to reuse.
        $this->makeVendor('V-902', 'Standby Plumbing');
        $current = $this->currentPlumbingWorkOrder(10);

        $this->generate($current);

        $this->assertNotSame('repeat_issue', $this->recommendationFor($current)->vendor_source);
        $this->assertDatabaseCount('work_order_vendors', 0);
        Notification::assertNothingSent();
    }
}
