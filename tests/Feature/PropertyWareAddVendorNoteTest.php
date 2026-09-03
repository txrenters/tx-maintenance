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

    public function test_an_http_error_page_is_reported_as_failure_and_no_id_is_stamped(): void
    {
        $note = $this->note();

        $service = $this->serviceWithExecuteReturning([
            'success' => false,
            'error' => 'HTTP_ERROR',
            'http_code' => 503,
            'message' => 'HTTP 503: <html><body>Service Unavailable</body></html>',
        ]);

        $this->assertFalse($service->addVendorNotes($note));
        $this->assertNull($note->fresh()->propertyware_id);
    }

    /**
     * The transport's verdict on an answer, without curl.
     */
    private function classifier(): PropertyWareService
    {
        return new class extends PropertyWareService
        {
            /**
             * @return array{success: bool, http_code: int, error?: string, message?: string, response?: string}
             */
            public function classify(string|false $body, int $httpCode, int $curlErrno = 0, string $curlError = ''): array
            {
                return $this->classifySoapResponse($body, $httpCode, $curlErrno, $curlError);
            }
        };
    }

    public function test_an_error_page_without_a_fault_element_is_not_a_success(): void
    {
        $result = $this->classifier()->classify('<html><body><h1>503 Service Unavailable</h1></body></html>', 503);

        $this->assertFalse($result['success']);
        $this->assertSame('HTTP_ERROR', $result['error']);
        $this->assertSame(503, $result['http_code']);
        $this->assertStringContainsString('503 Service Unavailable', $result['message']);
        $this->assertArrayNotHasKey('response', $result);
    }

    public function test_a_fault_is_recognised_whatever_its_namespace_prefix(): void
    {
        foreach (['soapenv:Fault', 'soap:Fault', 'SOAP-ENV:Fault', 'Fault'] as $tag) {
            $result = $this->classifier()->classify("<soapenv:Body><{$tag}><faultstring>Bad note</faultstring></{$tag}></soapenv:Body>", 500);

            $this->assertFalse($result['success'], $tag);
            $this->assertSame('SOAP_FAULT', $result['error'], $tag);
            $this->assertSame(500, $result['http_code'], $tag);
            $this->assertStringContainsString('Bad note', $result['message'], $tag);
        }
    }

    public function test_a_transport_error_is_reported_before_anything_else(): void
    {
        $result = $this->classifier()->classify(false, 0, 6, 'Could not resolve host: app.propertyware.com');

        $this->assertFalse($result['success']);
        $this->assertSame('CURL_ERROR', $result['error']);
        $this->assertSame(0, $result['http_code']);
        $this->assertSame('Could not resolve host: app.propertyware.com', $result['message']);
    }

    public function test_a_normal_answer_is_a_success_that_carries_its_status(): void
    {
        $result = $this->classifier()->classify('<soapenv:Envelope><soapenv:Body><ns1:attachNoteToWorkOrderResponse/></soapenv:Body></soapenv:Envelope>', 200);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertStringContainsString('attachNoteToWorkOrderResponse', $result['response']);
    }

    public function test_notes_reader_answers_unknown_when_propertyware_cannot_be_read_or_finds_another_number(): void
    {
        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldReceive('getWorkOrderByNumber')->once()->andReturn('Error: SOAP-ERROR: Parsing WSDL');
        $this->assertNull($service->workOrderNotesFromPropertyWare(43649));

        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldReceive('getWorkOrderByNumber')->once()->andReturn([['number' => 43650, 'notes' => [['ID' => 1]]]]);
        $this->assertNull($service->workOrderNotesFromPropertyWare(43649));

        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldReceive('getWorkOrderByNumber')->once()->andReturn([]);
        $this->assertNull($service->workOrderNotesFromPropertyWare(43649));
    }

    public function test_notes_reader_returns_the_work_orders_notes_or_an_empty_list(): void
    {
        // A lone row decodes as one associative record, and one with no notes
        // key means no notes.
        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldReceive('getWorkOrderByNumber')->once()->andReturn(['number' => '43649', 'description' => 'Tree branch']);
        $this->assertSame([], $service->workOrderNotesFromPropertyWare(43649));

        $notes = [['ID' => 8001, 'subject' => 'Closing Comment', 'body' => 'Invoice uploaded.', 'private' => true]];
        $service = Mockery::mock(PropertyWareService::class)->makePartial();
        $service->shouldReceive('getWorkOrderByNumber')->once()->andReturn([['number' => 43649, 'notes' => $notes]]);
        $this->assertSame($notes, $service->workOrderNotesFromPropertyWare('43649'));
    }
}
