<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\FallbackVendor;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_generate_a_work_order_recommendation_using_prior_vendor_history(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Completed',
            'description' => 'Completed',
        ]);

        $vendorUser = User::factory()->create();
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-100',
            'name' => 'Austin Plumbing Pros',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $history = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Kitchen sink leak under cabinet',
            'closing_comments' => 'Plumber repaired sink leak and replaced supply line.',
            'type' => 'Plumbing Repair',
            'category' => 'Plumbing',
            'completed_date' => now()->subDays(14),
            'building_id' => 10,
            'location' => 'Austin',
        ]);
        $history->vendors()->attach($vendor->id);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Tenant reports leaking kitchen sink again',
            'latest_update_comments' => 'Water leaking from under the sink after disposal use.',
            'type' => 'Repair',
            'category' => 'Maintenance',
            'building_id' => 10,
            'location' => 'Austin',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.issue_type', 'Plumbing')
            ->assertJsonPath('recommendation.recommended_vendor.id', $vendor->id);

        $this->assertDatabaseHas('work_order_recommendations', [
            'work_order_id' => $currentWorkOrder->id,
            'recommended_vendor_id' => $vendor->id,
            'issue_type' => 'Plumbing',
        ]);
    }

    public function test_recommendation_falls_back_to_vendor_type_when_no_history_exists(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $electricalVendorUser = User::factory()->create();
        $electricalVendor = Vendor::query()->create([
            'propertyware_id' => 'V-200',
            'name' => 'Capital Electric',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'user_id' => $electricalVendorUser->id,
        ]);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Outlet in bedroom has no power',
            'latest_update_comments' => 'Breaker may be tripping repeatedly.',
            'type' => 'Repair',
            'category' => 'Maintenance',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.issue_type', 'Electrical')
            ->assertJsonPath('recommendation.recommended_vendor.id', $electricalVendor->id);
    }

    public function test_building_maintenance_notice_does_not_override_unrelated_issue_type(): void
    {
        // Regression for work order #42818: handrail repair at a building whose
        // maintenance notice names an A/C vendor was being classified as HVAC and
        // recommended the owner's A/C vendor. The notice should only apply when the
        // issue type matches what the notice is about.
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $hvacVendor = Vendor::query()->create([
            'propertyware_id' => 'V-300',
            'name' => "Vo's A/C Service",
            'vendor_type' => 'HVAC',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $handymanVendor = Vendor::query()->create([
            'propertyware_id' => 'V-301',
            'name' => 'Texas Home Maintenance Pros',
            'vendor_type' => 'Handyman',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $building = Building::query()->create([
            'propertyware_id' => 'B-7902',
            'name' => '7902 Avenue F',
            'address' => '7902 Avenue F',
            'city' => 'Houston',
            'state_region' => 'TX',
            'maintenance_notice' => "Vo's A/C service #281 781-6875 is who put in the new A/C system when we purchased the property. I would prefer you use him for any of our A/C problems if possible.",
        ]);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Need to install handrails on the concrete steps at the back of the property as soon as possible. Urgent safety concern.',
            'type' => 'Service Request',
            'category' => 'Structural',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.issue_type', 'Carpentry');

        $this->assertNotEquals(
            $hvacVendor->id,
            $response->json('recommendation.recommended_vendor.id'),
            'Should not recommend the HVAC vendor mentioned in the building notice for a structural job.'
        );

        $this->assertEquals(
            $handymanVendor->id,
            $response->json('recommendation.recommended_vendor.id'),
            'Should fall through to the handyman vendor via category match.'
        );
    }

    public function test_building_history_outranks_owner_preferred_vendor_when_both_apply(): void
    {
        // The property's own track record beats the maintenance notice — if a
        // vendor previously fixed the same issue cleanly at this building, recommend
        // them even if the notice names a different preferred vendor.
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Completed',
            'description' => 'Completed',
        ]);

        $noticePreferredVendor = Vendor::query()->create([
            'propertyware_id' => 'V-400',
            'name' => 'Burnside Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $historyVendor = Vendor::query()->create([
            'propertyware_id' => 'V-401',
            'name' => 'Pacheco Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $building = Building::query()->create([
            'propertyware_id' => 'B-9001',
            'name' => 'Test Property',
            'maintenance_notice' => 'Plumbing: Burnside Plumbing 832-555-1212',
        ]);

        $pastWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Kitchen sink clogged and leaking',
            'closing_comments' => 'Cleared clog and replaced trap. All good.',
            'type' => 'Plumbing Repair',
            'category' => 'Plumbing',
            'completed_date' => now()->subDays(30),
        ]);
        $pastWorkOrder->vendors()->attach($historyVendor->id);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Bathroom sink leak',
            'type' => 'Service Request',
            'category' => 'Plumbing',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        // The history vendor wins over the owner-preferred notice. Prior same-issue
        // work at this same building also makes this a repeat, so it is labeled
        // repeat_issue — the stronger form of building history.
        $response->assertOk()
            ->assertJsonPath('recommendation.issue_type', 'Plumbing')
            ->assertJsonPath('recommendation.recommended_vendor.id', $historyVendor->id)
            ->assertJsonPath('recommendation.vendor_source', 'repeat_issue');
    }

    public function test_history_with_rework_signals_in_closing_comments_is_demoted(): void
    {
        // A vendor whose past job at this building closed with rework signals
        // (recall, callback, "still leaking") should lose to a vendor whose
        // past job closed cleanly.
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Completed',
            'description' => 'Completed',
        ]);

        $reworkVendor = Vendor::query()->create([
            'propertyware_id' => 'V-500',
            'name' => 'Sloppy Plumbing Co',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $cleanVendor = Vendor::query()->create([
            'propertyware_id' => 'V-501',
            'name' => 'Reliable Plumbing Co',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $building = Building::query()->create([
            'propertyware_id' => 'B-9002',
            'name' => 'Rework Test Property',
        ]);

        $reworkJob = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Kitchen sink leak',
            'closing_comments' => 'Tenant reported still leaking after visit. Had to come back for a callback.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'completed_date' => now()->subDays(10),
        ]);
        $reworkJob->vendors()->attach($reworkVendor->id);

        $cleanJob = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Bathroom sink leak under cabinet',
            'closing_comments' => 'Tightened compression fitting. Resolved.',
            'type' => 'Plumbing',
            'category' => 'Plumbing',
            'completed_date' => now()->subDays(60),
        ]);
        $cleanJob->vendors()->attach($cleanVendor->id);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'building_id' => $building->propertyware_id,
            'description' => 'Sink leaking again',
            'type' => 'Service Request',
            'category' => 'Plumbing',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.recommended_vendor.id', $cleanVendor->id);
    }

    public function test_recommendation_includes_curated_fallback_vendors_when_database_vendor_is_missing(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        // The curated fallbacks live in the fallback_vendors table (see
        // FallbackVendorSeeder), which RefreshDatabase wipes — arrange the one
        // this test asserts on rather than relying on seeded data.
        // Inactive on purpose: a curated fallback is what staff fall back to
        // precisely when no assignable database vendor matches, and an active
        // one would be recommended outright and filtered back out of this list.
        $expressKeyUser = User::factory()->create();
        $expressKey = Vendor::query()->create([
            'propertyware_id' => 'V-900',
            'name' => 'Express Key',
            'vendor_type' => 'Locksmith',
            'is_active' => false,
            'user_id' => $expressKeyUser->id,
        ]);

        FallbackVendor::query()->create([
            'vendor_id' => $expressKey->id,
            'contacts' => [['phone' => '(512) 800-3464']],
            'notes' => 'Use when the tenant locks themselves out of the home or garage.',
            'issue_types' => ['Lockout'],
            'keywords' => ['lockout', 'locked out', 'garage lockout'],
            'priority' => 10,
            'is_active' => true,
        ]);

        $currentWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Tenant is locked out of the home and garage.',
            'latest_update_comments' => 'Lockout request after hours.',
            'type' => 'Emergency',
            'category' => 'Access',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $currentWorkOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.issue_type', 'Lockout')
            ->assertJsonPath('recommendation.alternate_vendors.fallback.0.name', 'Express Key');
    }

    public function test_the_heuristic_classification_carries_the_tenant_easy_fix_verdict(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Garbage disposal is humming but not turning',
            'type' => 'Repair',
            'category' => 'Garbage Disposal',
            'easy_fix_key' => 'disposal_jammed',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.classification.is_tenant_easy_fix', true)
            ->assertJsonPath('recommendation.classification.easy_fix_key', 'disposal_jammed')
            ->assertJsonPath('recommendation.classification.tenant_responsibility_reason', null)
            ->assertJsonPath('recommendation.work_order.easy_fix_key', 'disposal_jammed');
    }

    public function test_the_heuristic_classification_explains_an_appliance_of_unknown_ownership(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'description' => 'Refrigerator stopped cooling',
            'type' => 'Repair',
            'category' => 'Refrigerator',
        ]);

        $response = $this->actingAs($user)
            ->post(route('work_orders.recommendation.generate', $workOrder));

        $response->assertOk()
            ->assertJsonPath('recommendation.classification.is_tenant_easy_fix', false)
            ->assertJsonPath('recommendation.classification.easy_fix_key', null);

        $this->assertStringContainsString('ownership is unknown', $response->json('recommendation.classification.tenant_responsibility_reason'));
    }
}
