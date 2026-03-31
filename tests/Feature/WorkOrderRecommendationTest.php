<?php

namespace Tests\Feature;

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

    public function test_recommendation_includes_curated_fallback_vendors_when_database_vendor_is_missing(): void
    {
        $user = User::factory()->create();
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
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
}
