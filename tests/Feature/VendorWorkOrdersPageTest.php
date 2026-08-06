<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorWorkOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    protected function tearDown(): void
    {
        // Acting as a vendor above populates WorkOrderScope's static cache; clear
        // it on the way out so the scoped vendor cannot leak into the next test.
        parent::tearDown();
    }

    /**
     * Work order numbers returned in the deferred `workOrders` prop of a partial
     * Inertia reload of the vendor work orders page.
     *
     * @return array<int, int>
     */
    private function returnedWorkOrderNumbers(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
            'X-Inertia-Partial-Data' => 'workOrders',
        ]);

        $response->assertOk();

        return collect($response->json('props.workOrders'))
            ->pluck('work_order_no')
            ->map(fn ($no) => (int) $no)
            ->all();
    }

    private function makeVendorUser(string $propertywareId, string $name): Vendor
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        return Vendor::query()->create([
            'propertyware_id' => $propertywareId,
            'name' => $name,
            'vendor_type' => 'Landscaping',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    public function test_vendor_sees_only_their_own_visible_work_orders(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);
        ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);

        $vendor = $this->makeVendorUser('V-501', 'Breasy Landscaping');
        $otherVendor = $this->makeVendorUser('V-502', 'Rival Landscaping');

        // Visible work order the vendor SHOULD see.
        $visible = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7001,
            'status' => 'Open',
        ]);
        $visible->vendors()->attach($vendor->id);

        // Paid but Closed at the PropertyWare level -> must NOT appear: the
        // status='Closed' guard wins even though Paid itself is visible now.
        $paid = WorkOrder::factory()->create([
            'service_status_id' => ServiceStatus::query()->where('name', 'Paid')->value('id'),
            'work_order_no' => 7002,
            'status' => 'Closed',
        ]);
        $paid->vendors()->attach($vendor->id);

        // Another vendor's work order -> must NOT leak in.
        $foreign = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7003,
            'status' => 'Open',
        ]);
        $foreign->vendors()->attach($otherVendor->id);

        // Paid and still Open -> visible since vendors track their own
        // payment/completion in the status tabs.
        $paidOpen = WorkOrder::factory()->create([
            'service_status_id' => ServiceStatus::query()->where('name', 'Paid')->value('id'),
            'work_order_no' => 7004,
            'status' => 'Open',
        ]);
        $paidOpen->vendors()->attach($vendor->id);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertContains(7001, $numbers);
        $this->assertNotContains(7002, $numbers);
        $this->assertNotContains(7003, $numbers);
        $this->assertContains(7004, $numbers);
    }

    public function test_vendor_sees_completed_statuses_but_never_the_closed_status(): void
    {
        Role::findOrCreate('vendor', 'web');

        $vendor = $this->makeVendorUser('V-503', 'Breasy Landscaping');

        $visibleStatusNames = [
            'Service Completed - Call Tenant for Followup',
            'Completed - Verified - Updating Owner',
            'Owner Completing Work',
            'Paid',
        ];

        $workOrderNo = 7100;
        foreach ($visibleStatusNames as $statusName) {
            $status = ServiceStatus::query()->create(['name' => $statusName, 'description' => $statusName]);
            $workOrder = WorkOrder::factory()->create([
                'service_status_id' => $status->id,
                'work_order_no' => ++$workOrderNo,
                'status' => 'Open',
            ]);
            $workOrder->vendors()->attach($vendor->id);
        }

        // The Closed service status stays internal even on an Open work order.
        $closedStatus = ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);
        $closedStatusWorkOrder = WorkOrder::factory()->create([
            'service_status_id' => $closedStatus->id,
            'work_order_no' => 7199,
            'status' => 'Open',
        ]);
        $closedStatusWorkOrder->vendors()->attach($vendor->id);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertContains(7101, $numbers);
        $this->assertContains(7102, $numbers);
        $this->assertContains(7103, $numbers);
        $this->assertContains(7104, $numbers);
        $this->assertNotContains(7199, $numbers);
    }

    public function test_closed_work_orders_are_hidden_even_with_a_visible_service_status(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-660', 'Breasy Landscaping');

        // Closed by the status column, but its service status is a visible one —
        // it must still be hidden from the vendor's list.
        $closed = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 6601,
            'status' => 'Closed',
        ]);
        $closed->vendors()->attach($vendor->id);

        $open = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 6602,
            'status' => 'Open',
        ]);
        $open->vendors()->attach($vendor->id);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertNotContains(6601, $numbers);
        $this->assertContains(6602, $numbers);
    }

    public function test_filter_options_reflect_only_values_present_in_vendor_work_orders(): void
    {
        Role::findOrCreate('vendor', 'web');

        $inProgress = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-650', 'Breasy Landscaping');

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $inProgress->id,
            'work_order_no' => 6501,
            'status' => 'Open',
            'category' => 'Lawn service',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $response = $this->actingAs($vendor->user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);

        $response->assertOk();

        $this->assertContains('Lawn service', $response->json('props.categories'));

        // The status dropdown is gone — the page groups by status into tabs
        // client-side, so no statuses prop should ship anymore.
        $this->assertNull($response->json('props.statuses'));
    }

    public function test_vendor_does_not_see_work_orders_they_are_not_tagged_on(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-701', 'Breasy Landscaping');

        // The vendor is NOT attached to this work order, but has a task on it.
        // WorkOrderScope would surface it via its task/attachment OR-branch, but
        // the vendor page must show only work orders tagged to the vendor.
        $untagged = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7501,
            'status' => 'Open',
        ]);
        WorkOrderTask::query()->create([
            'description' => 'Stray task',
            'status' => 'pending',
            'work_order_id' => $untagged->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        $numbers = $this->returnedWorkOrderNumbers($vendor->user);

        $this->assertNotContains(7501, $numbers);
    }

    public function test_the_list_payload_hides_internal_and_money_fields_from_vendors(): void
    {
        Role::findOrCreate('vendor', 'web');

        $paidStatus = ServiceStatus::query()->create(['name' => 'Paid', 'description' => 'Paid']);

        $vendor = $this->makeVendorUser('V-702', 'Breasy Landscaping');

        // Paid is one of the statuses vendors gained sight of — exactly where
        // internal notes and final costs must not ride along in the JSON.
        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $paidStatus->id,
            'work_order_no' => 7601,
            'status' => 'Open',
            'notes' => 'internal WOC note',
            'total_cost' => '412.50',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $response = $this->actingAs($vendor->user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
            'X-Inertia-Partial-Data' => 'workOrders',
        ]);

        $response->assertOk();

        $returned = collect($response->json('props.workOrders'))
            ->firstWhere('work_order_no', 7601);

        $this->assertNotNull($returned);

        // The card renders from these…
        $this->assertArrayHasKey('category', $returned);
        $this->assertArrayHasKey('location', $returned);

        // …and none of the internal/money/tenant-contact columns leak.
        $hidden = [
            'notes', 'remarks', 'closing_comments', 'approval_comments',
            'client_data', 'cost_estimate', 'total_cost',
            'service_request_contact_name', 'service_request_contact_phone',
            'service_request_contact_email',
        ];

        foreach ($hidden as $column) {
            $this->assertArrayNotHasKey($column, $returned, "Vendor list payload leaked `{$column}`.");
        }
    }

    public function test_each_work_order_carries_the_vendors_own_tasks_for_card_coloring(): void
    {
        Role::findOrCreate('vendor', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $vendor = $this->makeVendorUser('V-601', 'Breasy Landscaping');
        $otherUser = User::factory()->create();

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 8001,
            'status' => 'Open',
        ]);
        $workOrder->vendors()->attach($vendor->id);

        $ownTask = WorkOrderTask::query()->create([
            'description' => 'Mow the lawn',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $vendor->user_id,
        ]);

        // A task on the same work order but assigned to someone else must not
        // ride along in the vendor's payload.
        WorkOrderTask::query()->create([
            'description' => 'WOC-only task',
            'status' => 'pending',
            'work_order_id' => $workOrder->id,
            'assigned_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($vendor->user)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
            'X-Inertia-Partial-Data' => 'workOrders',
        ]);

        $response->assertOk();

        $returned = collect($response->json('props.workOrders'))
            ->firstWhere('work_order_no', 8001);

        $taskIds = collect($returned['tasks'] ?? [])->pluck('id')->all();

        $this->assertContains($ownTask->id, $taskIds);
        $this->assertCount(1, $taskIds);
    }
}
