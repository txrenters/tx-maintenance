<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PropertyWareAddVendorNoteTest extends TestCase
{
    use RefreshDatabase;

    private function note(?int $propertyWareWorkOrderId = 777001): WorkOrderNotes
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => $propertyWareWorkOrderId]);

        return WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Tom & Jerry <kitchen>',
            'body' => "Temp < 60°.\nA/C & heat both out.",
            'user_id' => User::factory()->create()->id,
            'is_private' => true,
        ]);
    }

    /**
     * @return PropertyWareService&MockInterface
     */
    private function serviceWithExecuteReturning(array $response, ?callable $assertPayload = null): PropertyWareService
    {
        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $expectation = $service->shouldReceive('execute')->once()->andReturn($response);

        if ($assertPayload !== null) {
            $expectation->withArgs(fn (string $xml) => $assertPayload($xml));
        }

        return $service;
    }

    public function test_note_is_sent_escaped_private_and_with_a_real_datetime_then_stamped_with_its_id(): void
    {
        $note = $this->note();

        $service = $this->serviceWithExecuteReturning(
            [
                'success' => true,
                'response' => '<soapenv:Envelope><soapenv:Body><ns1:attachNoteToWorkOrderResponse>'
                    .'<attachNoteToWorkOrderReturn href="#id0"/></ns1:attachNoteToWorkOrderResponse>'
                    .'<multiRef id="id0" xsi:type="ns2:Note"><ID xsi:type="xsd:long">987654</ID>'
                    .'<subject xsi:type="xsd:string">Tom &amp; Jerry</subject></multiRef>'
                    .'</soapenv:Body></soapenv:Envelope>',
            ],
            function (string $xml): bool {
                $this->assertStringContainsString('<subject xsi:type="xsd:string">Tom &amp; Jerry &lt;kitchen&gt;</subject>', $xml);
                $this->assertStringContainsString('A/C &amp; heat both out.', $xml);
                $this->assertStringNotContainsString('Tom & Jerry <kitchen>', $xml);
                $this->assertStringContainsString('<private xsi:type="xsd:boolean">1</private>', $xml);
                $this->assertStringContainsString('<ID xsi:type="xsd:long">777001</ID>', $xml);
                $this->assertMatchesRegularExpression('/<date xsi:type="xsd:dateTime">\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z<\/date>/', $xml);

                return true;
            },
        );

        $this->assertTrue($service->addVendorNotes($note));
        $this->assertSame('987654', (string) $note->fresh()->propertyware_id);
    }

    public function test_a_soap_fault_is_reported_as_failure(): void
    {
        $note = $this->note();

        $service = $this->serviceWithExecuteReturning([
            'success' => false,
            'error' => 'SOAP_FAULT',
            'message' => '<soapenv:Fault>Invalid note</soapenv:Fault>',
        ]);

        $this->assertFalse($service->addVendorNotes($note));
        $this->assertNull($note->fresh()->propertyware_id);
    }

    public function test_a_curl_error_is_reported_as_failure(): void
    {
        $note = $this->note();

        $service = $this->serviceWithExecuteReturning([
            'success' => false,
            'error' => 'CURL_ERROR',
            'message' => 'Connection timed out',
        ]);

        $this->assertFalse($service->addVendorNotes($note));
    }

    public function test_work_order_without_a_propertyware_id_is_never_pushed(): void
    {
        $note = $this->note(null);

        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldNotReceive('execute');

        $this->assertFalse($service->addVendorNotes($note));
    }

    public function test_an_ambiguous_response_still_counts_as_pushed_but_leaves_the_id_unset(): void
    {
        $note = $this->note();

        $service = $this->serviceWithExecuteReturning([
            'success' => true,
            'response' => '<multiRef><ID xsi:type="xsd:long">111</ID></multiRef><multiRef><ID xsi:type="xsd:long">222</ID></multiRef>'
                .'<multiRef><ID xsi:type="xsd:long">777001</ID></multiRef>',
        ]);

        $this->assertTrue($service->addVendorNotes($note));
        $this->assertNull($note->fresh()->propertyware_id);
    }
}
