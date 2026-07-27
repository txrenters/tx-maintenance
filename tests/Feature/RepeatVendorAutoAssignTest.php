<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\NewWorkOrderAssignNotification;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RepeatVendorAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(string $pwId, string $name): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
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
        $serviceStatus = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $vendor = $this->makeVendor('V-900', 'Reliable Plumbing');

        $prior = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Kitchen sink drain clog cleared.',
            'closing_comments' => 'Snaked the drain, flowing well.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'completed_date' => now()->subDays(20),
            'building_id' => 10,
            'location' => 'Austin',
            'status' => 'Closed',
        ]);
        $prior->vendors()->attach($vendor->id);

        $current = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Kitchen sink drain is backing up and the faucet is leaking under the sink.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'building_id' => 10,
            'location' => 'Austin',
            'is_emergency' => null,
        ]);

        return [$vendor, $current];
    }

    private function generate(WorkOrder $workOrder): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('work_orders.recommendation.generate', $workOrder))
            ->assertOk();
    }

    public function test_auto_assign_stays_off_when_the_gate_is_disabled(): void
    {
        // phpunit.xml pins VENDOR_AUTO_ASSIGN_ENABLED=false (the code default is
        // ON for production), so even a clear repeat is not auto-assigned and no
        // vendor is emailed — nothing outward happens while the gate is off.
        Notification::fake();
        [$vendor, $current] = $this->repeatScenario();

        $this->generate($current);

        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_enabled_it_assigns_the_prior_building_vendor_and_notifies_them(): void
    {
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->once();

        [$vendor, $current] = $this->repeatScenario();

        $this->generate($current);

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertSentTo($vendor, NewWorkOrderAssignNotification::class);
    }

    public function test_it_never_overrides_a_vendor_a_human_already_assigned(): void
    {
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->never();

        [$vendor, $current] = $this->repeatScenario();

        // A coordinator already picked a different vendor.
        $other = $this->makeVendor('V-901', 'Other Plumbing');
        $current->vendors()->attach($other->id);

        $this->generate($current);

        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_a_closed_work_order_is_never_auto_assigned(): void
    {
        // Generation can fire later in a work order's life (recommendation tab
        // auto-generate, manual PropertyWare refresh) on old or closed work
        // orders with no vendor linked — those must never email a vendor.
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->never();

        [$vendor, $current] = $this->repeatScenario();
        $current->update(['status' => 'Closed', 'completed_date' => now()->subDay()]);

        $this->generate($current);

        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $vendor->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_a_non_repeat_is_not_auto_assigned_even_when_enabled(): void
    {
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->never();

        // No prior history at all — a first-time issue, so no "same vendor" to reuse.
        $serviceStatus = ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        $this->makeVendor('V-902', 'Standby Plumbing');
        $current = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Kitchen sink drain is backing up and the faucet is leaking under the sink.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'building_id' => 10,
            'location' => 'Austin',
            'is_emergency' => null,
        ]);

        $this->generate($current);

        $this->assertDatabaseCount('work_order_vendors', 0);
        Notification::assertNothingSent();
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

    private function completedJob(int $buildingId, string $type, Vendor $vendor, int $monthsAgo): void
    {
        $serviceStatus = ServiceStatus::query()->firstWhere('name', 'New')
            ?? ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        $job = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
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
        $serviceStatus = ServiceStatus::query()->firstWhere('name', 'New')
            ?? ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Kitchen sink drain is backing up and the faucet is leaking under the sink.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'building_id' => $buildingId,
            'location' => 'Austin',
            'is_emergency' => null,
        ]);
    }

    public function test_it_reuses_the_same_issue_vendor_not_a_more_recent_different_issue_vendor(): void
    {
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->once();

        // Same-issue plumbing job (older) with the right vendor; a more RECENT
        // HVAC job at the same building with a different active vendor.
        $plumber = $this->makeTypedVendor('V-910', 'Correct Plumbing', 'Plumbing', true);
        $hvac = $this->makeTypedVendor('V-911', 'Wrong HVAC', 'HVAC', true);
        $this->completedJob(10, 'Plumbing', $plumber, 6);
        $this->completedJob(10, 'HVAC', $hvac, 1);

        $current = $this->currentPlumbingWorkOrder(10);
        $this->generate($current);

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $plumber->id,
        ]);
        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $hvac->id,
        ]);
        Notification::assertSentTo($plumber, NewWorkOrderAssignNotification::class);
        Notification::assertNotSentTo($hvac, NewWorkOrderAssignNotification::class);
    }

    public function test_it_stays_hands_off_when_the_same_issue_prior_vendor_is_inactive(): void
    {
        config(['services.work_order.auto_assign_vendor' => true]);
        Notification::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('changeWorkOrderVendors')->never();

        // The plumbing vendor who handled it before is now inactive; an active
        // HVAC vendor also worked this building. Neither may be auto-assigned.
        $inactivePlumber = $this->makeTypedVendor('V-920', 'Gone Plumbing', 'Plumbing', false);
        $hvac = $this->makeTypedVendor('V-921', 'Wrong HVAC', 'HVAC', true);
        $this->completedJob(10, 'Plumbing', $inactivePlumber, 3);
        $this->completedJob(10, 'HVAC', $hvac, 1);

        $current = $this->currentPlumbingWorkOrder(10);
        $this->generate($current);

        // Still flagged a repeat, but nobody is auto-assigned.
        $this->assertEquals(1, $current->fresh()->is_repeat_issue);
        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $hvac->id,
        ]);
        $this->assertDatabaseMissing('work_order_vendors', [
            'work_order_id' => $current->id,
            'vendor_id' => $inactivePlumber->id,
        ]);
        Notification::assertNothingSent();
    }
}
