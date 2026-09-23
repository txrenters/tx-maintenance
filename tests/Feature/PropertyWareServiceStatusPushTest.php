<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PropertyWareServiceStatusPushTest extends TestCase
{
    use RefreshDatabase;

    private function push(int $httpStatus): bool
    {
        Http::fake(['api.propertyware.com/*' => Http::response(['ok' => $httpStatus < 400], $httpStatus)]);

        $status = ServiceStatus::query()->create(['name' => 'Checking for Tenant Easy Fix', 'description' => 'easy fix']);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 43900001, 'work_order_no' => 43900]);

        return app(PropertyWareService::class)->updateServiceStatus($workOrder, $status);
    }

    public function test_it_reports_success_only_when_propertyware_accepts_the_status(): void
    {
        $this->assertTrue($this->push(200));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.propertyware.com/pw/api/rest/v1/workorders/customfields'
            && $request['entityId'] === 43900001
            && $request['fieldSetDTOS'][0]['name'] === 'Service Status'
            && $request['fieldSetDTOS'][0]['value'] === 'Checking for Tenant Easy Fix');
    }

    public function test_it_reports_failure_when_propertyware_rejects_the_status(): void
    {
        // Used to return true for any answer, which hid every rejection.
        $this->assertFalse($this->push(400));
    }
}
