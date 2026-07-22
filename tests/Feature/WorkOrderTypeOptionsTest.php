<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The editable "Type" dropdown on the work order details modal reads its
 * options from a `types` prop. That prop is the distinct list of types already
 * in use and must be delivered by every host that renders the modal — not just
 * the full-page Show view — or the dropdown renders empty (regression fixed
 * 2026-07-22). These tests lock the option list onto the board and the shared
 * modal-meta endpoint.
 */
class WorkOrderTypeOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        Role::findOrCreate('woc', 'web');

        $staff = User::factory()->create();
        $staff->assignRole('woc');

        return $staff;
    }

    private function seedTypedWorkOrders(): void
    {
        $openStatus = ServiceStatus::query()->create(['name' => 'In Progress', 'description' => 'In progress']);

        WorkOrder::factory()->create(['service_status_id' => $openStatus->id, 'status' => 'Open', 'type' => 'Turnover']);
        WorkOrder::factory()->create(['service_status_id' => $openStatus->id, 'status' => 'Open', 'type' => 'Repair']);
        // Legacy whitespace + a duplicate must collapse to one trimmed value.
        $legacy = WorkOrder::factory()->create(['service_status_id' => $openStatus->id, 'status' => 'Open']);
        DB::table('work_orders')->where('id', $legacy->id)->update(['type' => 'Repair ']);
        // Blank/null types must never become an option.
        WorkOrder::factory()->create(['service_status_id' => $openStatus->id, 'status' => 'Open', 'type' => null]);
    }

    public function test_board_index_delivers_type_options(): void
    {
        $this->seedTypedWorkOrders();

        $response = $this->actingAs($this->staffUser())->get(route('work_orders.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Component' => 'WorkOrder/Index',
            'X-Inertia-Partial-Data' => 'types',
        ]);

        $response->assertOk();

        $types = $response->json('props.types');

        $this->assertEqualsCanonicalizing(['Repair', 'Turnover'], $types);
    }

    public function test_modal_meta_endpoint_delivers_type_options(): void
    {
        $this->seedTypedWorkOrders();

        $response = $this->actingAs($this->staffUser())->getJson(route('api.work_order_modal.meta'));

        $response->assertOk();

        $this->assertEqualsCanonicalizing(['Repair', 'Turnover'], $response->json('types'));
    }
}
