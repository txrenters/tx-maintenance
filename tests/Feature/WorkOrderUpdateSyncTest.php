<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkOrderUpdateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        $status = ServiceStatus::query()->create([
            'name' => 'In Progress',
            'description' => 'In progress',
        ]);

        return WorkOrder::query()->create([
            'service_status_id' => $status->id,
            'work_order_no' => 7001,
            'propertyware_id' => 'PW-700',
            'category' => 'Plumbing',
            'is_approved' => 0,
        ]);
    }

    public function test_update_surfaces_propertyware_rejection_to_the_user(): void
    {
        // PropertyWare rejects the work order PATCH (e.g. it is closed on their side).
        Http::fake([
            '*/workorders/customfields' => Http::response([], 200),
            '*/workorders/*' => Http::response([
                'userMessage' => 'Can not edit work order. It is already closed',
                'errorCode' => '1001',
            ], 400),
            '*' => Http::response([], 200),
        ]);

        $user = User::factory()->create();
        $workOrder = $this->makeWorkOrder();

        $response = $this->actingAs($user)->put(route('work_orders.update', $workOrder), [
            'work_order_no' => $workOrder->work_order_no,
            'category' => 'Electrical',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', fn ($message) => str_contains($message, 'already closed'));
        $response->assertSessionMissing('success');
    }

    public function test_update_reports_success_when_propertyware_accepts(): void
    {
        Http::fake(); // every PropertyWare call returns 200

        $user = User::factory()->create();
        $workOrder = $this->makeWorkOrder();

        $response = $this->actingAs($user)->put(route('work_orders.update', $workOrder), [
            'work_order_no' => $workOrder->work_order_no,
            'category' => 'Electrical',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Local change persisted.
        $this->assertSame('Electrical', $workOrder->fresh()->category);
    }
}
