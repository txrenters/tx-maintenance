<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PropertyWare's approveWorkOrder takes approvedDate as an xsd:dateTime. Sent a
 * bare date it accepts the call, approves the work order and silently stores no
 * date — proven live on the demo work order on 2026-09-18, which came back
 * approved:true with approvedDate:null. These pin the datetime that goes on the
 * wire, since the response says nothing about what was kept.
 */
class PropertyWareApprovedDateTest extends TestCase
{
    use RefreshDatabase;

    private function workOrder(mixed $approvedDate): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->firstOrCreate(
            ['name' => 'New'],
            ['description' => 'New']
        );

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 43761,
            'propertyware_id' => '8157167685',
            'is_approved' => true,
            'approved_date' => $approvedDate,
            'approval_comments' => 'I approve this work order. - A. Owner, 09/18/2026',
        ]);
    }

    /**
     * A service whose SOAP client records the call instead of making it.
     *
     * @return array{0: PropertyWareService, 1: object}
     */
    private function recordingService(): array
    {
        $recorder = new class
        {
            public array $calls = [];

            public function approveWorkOrder(...$arguments): void
            {
                $this->calls[] = $arguments;
            }
        };

        $service = new class($recorder) extends PropertyWareService
        {
            public function __construct(private object $recorder)
            {
                parent::__construct();
            }

            public function initiate()
            {
                return $this->recorder;
            }
        };

        return [$service, $recorder];
    }

    public function test_a_local_date_goes_to_propertyware_as_a_datetime(): void
    {
        [$service, $recorder] = $this->recordingService();

        $service->approvedWorkOrder($this->workOrder('2026-09-18'));

        $this->assertCount(1, $recorder->calls);

        // The bug: '2026-09-18' went on the wire and PropertyWare kept nothing.
        $this->assertSame('2026-09-18T00:00:00', $recorder->calls[0][2]);
    }

    public function test_an_approval_with_no_date_is_dated_rather_than_sent_undated(): void
    {
        [$service, $recorder] = $this->recordingService();

        $service->approvedWorkOrder($this->workOrder(null));

        $this->assertCount(1, $recorder->calls);

        // Never empty: an approval PropertyWare stores without a date reads as
        // approved by nobody, on no date.
        $this->assertNotSame('', $recorder->calls[0][2]);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
            (string) $recorder->calls[0][2]
        );
    }

    public function test_an_unapproved_work_order_is_never_pushed(): void
    {
        [$service, $recorder] = $this->recordingService();

        $workOrder = $this->workOrder('2026-09-18');
        $workOrder->forceFill(['is_approved' => false])->save();

        $service->approvedWorkOrder($workOrder);

        $this->assertSame([], $recorder->calls);
    }
}
