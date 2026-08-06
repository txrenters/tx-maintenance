<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The kanban board payload must stay slim: serializing heavy columns
 * (client_data, description, notes, …) for every open work order exhausted
 * PHP's memory limit in production on 2026-07-22. The cards only need the
 * columns in WorkOrderController::BOARD_CARD_COLUMNS; the details modal
 * fetches the full record separately via work_orders.data.
 */
class WorkOrderBoardPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed> the first work order of the first non-empty status bucket
     */
    private function firstBoardWorkOrder(User $user): array
    {
        $response = $this->actingAs($user)->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'service_status',
        ]);

        $response->assertOk();

        $workOrder = collect($response->json('props.service_status'))
            ->flatMap(fn ($status) => $status['work_orders'] ?? [])
            ->first();

        $this->assertNotNull($workOrder, 'Expected at least one work order on the board.');

        return $workOrder;
    }

    public function test_board_payload_contains_card_fields_but_not_heavy_columns(): void
    {
        Role::findOrCreate('woc', 'web');

        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        $building = Building::query()->create([
            'propertyware_id' => 'B-9001',
            'name' => 'Maple Court',
            'city' => 'Nacogdoches',
        ]);

        $tenant = Tenants::factory()->create([
            'first_name' => 'Tina',
            'last_name' => 'Tenant',
            'client_data' => '{"huge":"propertyware blob"}',
        ]);

        $owner = Owner::factory()->create([
            'first_name' => 'Olive',
            'last_name' => 'Owner',
            'client_data' => '{"huge":"propertyware blob"}',
        ]);

        $vendorUser = User::factory()->create();
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-9001',
            'name' => 'Fixit Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'service_status_id' => $openStatus->id,
            'work_order_no' => 7001,
            'status' => 'Open',
            'category' => 'Plumbing',
            'building_id' => $building->propertyware_id,
            'tenant_id' => $tenant->id,
            'description' => str_repeat('very long description ', 50),
            'client_data' => '{"huge":"propertyware blob"}',
            'notes' => 'internal notes',
        ]);
        $workOrder->vendors()->attach($vendor->id);
        $workOrder->owners()->attach($owner->id);

        WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'description' => 'call the tenant',
            'status' => 'pending',
        ]);

        $staff = User::factory()->create();
        $staff->assignRole('woc');

        $payload = $this->firstBoardWorkOrder($staff);

        // Everything the card renders or filters on must be present.
        foreach ([
            'id', 'work_order_no', 'created_date', 'scheduled_end_date', 'location',
            'category', 'status', 'priority', 'is_approved', 'is_emergency',
            'is_repeat_issue', 'repeat_count',
        ] as $field) {
            $this->assertArrayHasKey($field, $payload);
        }

        $this->assertSame('Maple Court', $payload['building']['name']);
        // The client-side city show/hide filter reads building.city off the card.
        $this->assertSame('Nacogdoches', $payload['building']['city']);
        $this->assertSame('Tina', $payload['requested_by']['first_name']);
        $this->assertSame('Olive', $payload['owners'][0]['first_name']);
        $this->assertSame('Fixit Plumbing', $payload['vendors'][0]['name']);
        $this->assertSame('pending', $payload['tasks'][0]['status']);
        $this->assertArrayHasKey('due_date', $payload['tasks'][0]);

        // Heavy columns must never ride along on the board.
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('client_data', $payload);
        $this->assertArrayNotHasKey('notes', $payload);
        $this->assertArrayNotHasKey('client_data', $payload['requested_by']);
        $this->assertArrayNotHasKey('client_data', $payload['owners'][0]);
        $this->assertArrayNotHasKey('user', $payload['vendors'][0]);
        $this->assertArrayNotHasKey('description', $payload['tasks'][0]);
        $this->assertArrayNotHasKey('managed_by', $payload);
    }
}
