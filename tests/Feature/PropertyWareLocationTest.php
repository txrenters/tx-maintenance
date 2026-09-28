<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PropertyWare validates the `location` of a full-replace updateWorkOrder
 * against its own "PORTFOLIO | BUILDING" string and rejects the whole call
 * with "java.lang.RuntimeException: Location is invalid" when it differs —
 * which silently loses the vendor assignment (WO#40363, 2026-09-24).
 *
 * The REST work order hands back the same location with the pipe collapsed
 * to a space ("BLACKBIRDLLC 7811BLACKBIR" for PropertyWare's own
 * "BLACKBIRDLLC | 7811BLACKBIR"), and the REST importers store that, so any
 * work order last written by them pushed an invalid location.
 */
class PropertyWareLocationTest extends TestCase
{
    use RefreshDatabase;

    private const PW_LOCATION = 'BLACKBIRDLLC | 7811BLACKBIR';

    /** The same string as the REST API hands it back: the pipe collapsed to a space. */
    private const REST_LOCATION = 'BLACKBIRDLLC 7811BLACKBIR';

    /**
     * The real service with only its transport stubbed: `execute` captures the
     * envelope, `getWorkOrderByNumber` serves PropertyWare's SOAP copy.
     *
     * @param  array<string, mixed>  $soapRow
     */
    private function stubTransport(array $soapRow, ?string &$capturedXml): PropertyWareService
    {
        return $this->partialMock(PropertyWareService::class, function ($mock) use ($soapRow, &$capturedXml) {
            $mock->shouldReceive('execute')->andReturnUsing(function (string $xml) use (&$capturedXml) {
                $capturedXml = $xml;

                return ['success' => true, 'error' => null, 'message' => 'ok'];
            });
            $mock->shouldReceive('getWorkOrderByNumber')->andReturn([$soapRow]);
            $mock->shouldReceive('getWorkOrder')->andReturn(['approved' => false]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapRow(array $overrides = []): array
    {
        return array_merge([
            'number' => 40363,
            'approved' => false,
            'location' => self::PW_LOCATION,
        ], $overrides);
    }

    private function workOrder(?string $storedLocation): WorkOrder
    {
        return WorkOrder::factory()->create([
            'propertyware_id' => 7526613051,
            'work_order_no' => 40363,
            'category' => 'Lawn service',
            'type' => 'Biweekly Lawn Services',
            'building_id' => 233900017,
            'portfolio_id' => 228295001,
            'location' => $storedLocation,
        ]);
    }

    private function locationIn(string $xml): ?string
    {
        return preg_match('/<location xsi:type="xsd:string">(.*?)<\/location>/s', $xml, $m) === 1 ? $m[1] : null;
    }

    public function test_the_vendor_push_sends_the_location_propertyware_itself_holds(): void
    {
        $capturedXml = null;
        $service = $this->stubTransport($this->soapRow(), $capturedXml);
        $workOrder = $this->workOrder(self::REST_LOCATION);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertSame(self::PW_LOCATION, $this->locationIn($capturedXml));
    }

    public function test_the_location_read_from_propertyware_replaces_the_mangled_stored_one(): void
    {
        $capturedXml = null;
        $service = $this->stubTransport($this->soapRow(), $capturedXml);
        $workOrder = $this->workOrder(self::REST_LOCATION);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertSame(self::PW_LOCATION, $workOrder->refresh()->location);
    }

    /**
     * Checked live against the demo work order on 2026-09-29: PropertyWare
     * answers "Location is invalid" both to the REST-mangled string and to an
     * envelope with no location element at all, and accepts only its own.
     * With nothing valid to send, the push must not go out — a rejected
     * envelope loses the vendor assignment silently.
     */
    public function test_nothing_is_pushed_when_no_location_propertyware_would_accept_is_known(): void
    {
        $capturedXml = null;
        $service = $this->stubTransport($this->soapRow(['location' => null]), $capturedXml);
        // Only the REST-mangled copy is known, and PropertyWare would reject it.
        $workOrder = $this->workOrder(self::REST_LOCATION);

        try {
            $service->changeWorkOrderVendors($workOrder, '');
            $this->fail('Expected the push to be refused rather than rejected by PropertyWare.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('location', strtolower($e->getMessage()));
        }

        $this->assertNull($capturedXml, 'An envelope PropertyWare would reject must never be sent.');
    }

    public function test_a_stored_location_in_propertywares_own_format_is_still_sent(): void
    {
        $capturedXml = null;
        $service = $this->stubTransport($this->soapRow(['location' => null]), $capturedXml);
        $workOrder = $this->workOrder(self::PW_LOCATION);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertSame(self::PW_LOCATION, $this->locationIn($capturedXml));
    }

    public function test_the_work_order_details_push_also_sends_propertywares_location(): void
    {
        $capturedXml = null;
        $service = $this->stubTransport($this->soapRow(), $capturedXml);
        $workOrder = $this->workOrder(self::REST_LOCATION);

        $service->updateWorkOrderDetails($workOrder);

        $this->assertNotNull($capturedXml);
        $this->assertSame(self::PW_LOCATION, $this->locationIn($capturedXml));
    }

    public function test_the_rest_importers_never_overwrite_a_valid_location_with_the_mangled_one(): void
    {
        $workOrder = $this->workOrder(self::PW_LOCATION);

        $workOrder->fill(['location' => WorkOrder::importedLocation(self::REST_LOCATION, $workOrder->location)])->save();

        $this->assertSame(self::PW_LOCATION, $workOrder->refresh()->location);
    }

    public function test_an_imported_location_is_taken_when_nothing_better_is_known(): void
    {
        $this->assertSame(self::REST_LOCATION, WorkOrder::importedLocation(self::REST_LOCATION, null));
        $this->assertSame(self::PW_LOCATION, WorkOrder::importedLocation(self::PW_LOCATION, self::REST_LOCATION));
        $this->assertNull(WorkOrder::importedLocation(null, null));
        $this->assertSame(self::PW_LOCATION, WorkOrder::importedLocation(null, self::PW_LOCATION));
    }
}
