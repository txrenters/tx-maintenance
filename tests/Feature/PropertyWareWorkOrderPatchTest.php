<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PropertyWareWorkOrderPatchTest extends TestCase
{
    use RefreshDatabase;

    private function serviceStatusId(): int
    {
        return ServiceStatus::query()->create([
            'name' => 'In Progress',
            'description' => 'In progress',
        ])->id;
    }

    public function test_update_sends_merge_patch_with_only_changed_fields(): void
    {
        Http::fake();

        // Work order with no priority / authorized_to_enter / dates — exactly the case
        // that previously made us send invalid empty enum/date values and get a 400.
        $workOrder = WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatusId(),
            'work_order_no' => 5001,
            'propertyware_id' => 'PW-123',
            'category' => 'Plumbing',
            'description' => 'Fix sink',
        ]);

        (new PropertyWareService)->updateWorkOrder($workOrder, [
            'category' => 'Electrical',
            'description' => 'Rewire outlet',
        ]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data();

            // Only the changed standard fields are sent.
            $this->assertSame('Electrical', $body['category'] ?? null);
            $this->assertSame('Rewire outlet', $body['description'] ?? null);

            // The invalid empty enum/date fields are no longer sent, so PropertyWare
            // can't 400 the whole PATCH.
            $this->assertArrayNotHasKey('authorizedToEnter', $body);
            $this->assertArrayNotHasKey('priority', $body);
            $this->assertArrayNotHasKey('dateToEnter', $body);
            $this->assertArrayNotHasKey('startDate', $body);
            $this->assertArrayNotHasKey('scheduledEndDate', $body);
            $this->assertArrayNotHasKey('type', $body);

            return true;
        });
    }

    public function test_update_omits_empty_changed_fields_from_the_patch(): void
    {
        Http::fake();

        $workOrder = WorkOrder::query()->create([
            'service_status_id' => $this->serviceStatusId(),
            'work_order_no' => 5002,
            'propertyware_id' => 'PW-456',
            'category' => 'Plumbing',
        ]);

        // Category changes; description is submitted but empty.
        (new PropertyWareService)->updateWorkOrder($workOrder, [
            'category' => 'HVAC',
            'description' => '',
        ]);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data();

            $this->assertSame('HVAC', $body['category'] ?? null);
            $this->assertArrayNotHasKey('description', $body);

            return true;
        });
    }
}
