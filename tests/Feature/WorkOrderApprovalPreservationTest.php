<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The approval section (flag, approver, date, comment) is entered only in
 * PropertyWare, and its SOAP updateWorkOrder replaces the whole work order:
 * an envelope without those fields un-approves the work order there and
 * blanks the rest (WO#44014, WO#43819). Every full-replace push must echo
 * PropertyWare's own values, and nothing may re-approve through the app's
 * login on a normal save.
 */
class WorkOrderApprovalPreservationTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_COMMENT = 'We just opened a ticket with the home warranty company.';

    private const OWNER_ID = '2175795201';

    private const PW_APPROVED_DATE = '2026-08-17T05:00:00.000Z';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('vendor', 'web');
        Role::findOrCreate('admin', 'web');
    }

    /**
     * A call recorder standing in for the SOAP client behind approveWorkOrder.
     */
    private function soapRecorder(): object
    {
        return new class
        {
            /** @var array<int, array{0: string, 1: array<int, mixed>}> */
            public array $calls = [];

            public function __call(string $name, array $arguments): void
            {
                $this->calls[] = [$name, $arguments];
            }
        };
    }

    /**
     * The real PropertyWareService with only its transport stubbed: `execute`
     * captures the outgoing SOAP envelope, `getWorkOrderByNumber` serves the
     * SOAP copy the approval is read from, `getWorkOrder` serves the REST
     * reads made after the push (one value per read), `initiate` hands back
     * the recorder. (Http::fake cannot intercept execute() — raw curl — or
     * initiate(), which news up a \SoapClient.)
     *
     * @param  array<string, mixed>|string|null  $soapRow  the by-number result; a string plays a failed lookup
     * @param  array<int, array<string, mixed>|false>  $restReads
     */
    private function stubPropertyWareTransport(array|string|null $soapRow, array $restReads, ?string &$capturedXml, object $soapRecorder): PropertyWareService
    {
        return $this->partialMock(PropertyWareService::class, function ($mock) use ($soapRow, $restReads, &$capturedXml, $soapRecorder) {
            $mock->shouldReceive('execute')->andReturnUsing(function (string $xml) use (&$capturedXml) {
                $capturedXml = $xml;

                return ['success' => true, 'error' => null, 'message' => 'ok'];
            });
            $mock->shouldReceive('getWorkOrderByNumber')->andReturn(is_array($soapRow) ? [$soapRow] : ($soapRow ?? []));
            $mock->shouldReceive('getWorkOrder')->andReturn(...($restReads ?: [['approved' => true]]));
            $mock->shouldReceive('initiate')->andReturn($soapRecorder);
        });
    }

    /**
     * @return array<int, array<int, mixed>> the recorded approveWorkOrder calls
     */
    private function approveCalls(object $soapRecorder): array
    {
        return array_values(array_filter(
            $soapRecorder->calls,
            fn (array $call): bool => $call[0] === 'approveWorkOrder',
        ));
    }

    /**
     * PropertyWare's SOAP copy of an approved work order, in the shape the
     * import reads (the approver is a nested User object).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function approvedSoapRow(int $number, array $overrides = []): array
    {
        return array_merge([
            'number' => $number,
            'approved' => true,
            'approvedBy' => ['ID' => (int) self::OWNER_ID],
            'approvedDate' => self::PW_APPROVED_DATE,
            'approvalComment' => self::OWNER_COMMENT,
            'lease' => ['ID' => 4242],
        ], $overrides);
    }

    private function assignVendorThroughTheRoute(WorkOrder $workOrder): void
    {
        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $vendorUser->id,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole('admin');

        $this->actingAs($staff)
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$vendor->id]])
            ->assertSessionHas('success');
    }

    public function test_vendor_assign_echoes_propertyware_approval_and_does_not_re_approve(): void
    {
        Queue::fake();

        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $this->stubPropertyWareTransport($this->approvedSoapRow(44014), [['approved' => true]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888001,
            'work_order_no' => 44014,
            'lease_id' => 4242,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_by' => self::OWNER_ID,
            'approved_date' => '2026-08-17',
        ]);

        $this->assignVendorThroughTheRoute($workOrder);

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approved xsi:type="xsd:boolean">true</approved>', $capturedXml);
        $this->assertStringContainsString('<approvedBy xsi:type="urn:User"><ID xsi:type="xsd:long">'.self::OWNER_ID.'</ID></approvedBy>', $capturedXml);
        $this->assertStringContainsString('<approvedDate xsi:type="xsd:dateTime">'.self::PW_APPROVED_DATE.'</approvedDate>', $capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">'.self::OWNER_COMMENT.'</approvalComment>', $capturedXml);

        $this->assertCount(0, $this->approveCalls($recorder));

        $this->assertSame(self::OWNER_COMMENT, $workOrder->refresh()->approval_comments);
    }

    public function test_vendor_assign_uses_propertyware_values_over_a_stale_local_copy(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport($this->approvedSoapRow(44016), [['approved' => true]], $capturedXml, $recorder);

        // The owner approved minutes ago; the import hasn't caught up.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888003,
            'work_order_no' => 44016,
            'lease_id' => 4242,
            'is_approved' => false,
            'approval_comments' => null,
            'approved_by' => null,
            'approved_date' => null,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approved xsi:type="xsd:boolean">true</approved>', $capturedXml);
        $this->assertStringContainsString('<ID xsi:type="xsd:long">'.self::OWNER_ID.'</ID></approvedBy>', $capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">'.self::OWNER_COMMENT.'</approvalComment>', $capturedXml);

        $this->assertCount(0, $this->approveCalls($recorder));

        $workOrder->refresh();
        $this->assertTrue((bool) $workOrder->is_approved);
        $this->assertSame(self::OWNER_ID, $workOrder->approved_by);
        $this->assertSame('2026-08-17', $workOrder->approved_date);
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
    }

    public function test_vendor_assign_of_an_unapproved_work_order_keeps_it_unapproved(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport($this->approvedSoapRow(44015, [
            'approved' => false,
            'approvedBy' => null,
            'approvedDate' => null,
            'approvalComment' => 'Please send an estimate first.',
        ]), [], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888002,
            'work_order_no' => 44015,
            'lease_id' => 4242,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approved xsi:type="xsd:boolean">false</approved>', $capturedXml);
        $this->assertStringNotContainsString('<approvedBy', $capturedXml);
        $this->assertStringNotContainsString('<approvedDate', $capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">Please send an estimate first.</approvalComment>', $capturedXml);

        $this->assertCount(0, $this->approveCalls($recorder));

        $workOrder->refresh();
        $this->assertFalse((bool) $workOrder->is_approved);
        $this->assertSame('Please send an estimate first.', $workOrder->approval_comments);
    }

    public function test_vendor_assign_is_abandoned_when_the_approval_cannot_be_read(): void
    {
        // A stale local copy is what used to un-approve work orders, so a
        // failed lookup must stop the push, not feed it the local row.
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport('Error: SOAP request failed', [['approved' => true]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888004,
            'work_order_no' => 44017,
            'lease_id' => 4242,
            'is_approved' => false,
            'approval_comments' => null,
        ]);

        try {
            $service->changeWorkOrderVendors($workOrder, '');
            $this->fail('The vendor change should have been abandoned.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('could not be read', $e->getMessage());
        }

        $this->assertNull($capturedXml, 'Nothing may be sent to PropertyWare without a fresh approval read.');
        $this->assertCount(0, $this->approveCalls($recorder));
    }

    public function test_the_assign_vendor_button_reports_the_abandoned_push(): void
    {
        Queue::fake();

        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $this->stubPropertyWareTransport(null, [['approved' => true]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888009,
            'work_order_no' => 44022,
            'lease_id' => 4242,
            'is_approved' => true,
        ]);

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $vendorUser->id,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole('admin');

        $this->actingAs($staff)
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$vendor->id]])
            ->assertSessionHas('error');

        $this->assertNull($capturedXml);
        $this->assertSame(0, $workOrder->vendors()->count(), 'The local assignment rolls back with the failed push.');
    }

    public function test_an_approval_without_a_date_is_dated_so_propertyware_accepts_the_update(): void
    {
        // PropertyWare's own approve operation can leave the date empty, and
        // its updateWorkOrder then faults with "Approved Date is required".
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport($this->approvedSoapRow(44021, [
            'approvedDate' => null,
        ]), [['approved' => true]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888008,
            'work_order_no' => 44021,
            'lease_id' => 4242,
            'is_approved' => true,
            'approved_date' => null,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approved xsi:type="xsd:boolean">true</approved>', $capturedXml);
        $this->assertMatchesRegularExpression('/<approvedDate xsi:type="xsd:dateTime">\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}<\/approvedDate>/', $capturedXml);
        $this->assertCount(0, $this->approveCalls($recorder));
    }

    public function test_vendor_assign_restores_an_approval_propertyware_dropped(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport($this->approvedSoapRow(44018), [['approved' => false]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888005,
            'work_order_no' => 44018,
            'lease_id' => 4242,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_by' => self::OWNER_ID,
            'approved_date' => '2026-08-17',
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $approveCalls = $this->approveCalls($recorder);
        $this->assertCount(1, $approveCalls);
        $this->assertSame(888005, $approveCalls[0][1][0], 'approveWorkOrder takes the PropertyWare id, not the number');
        $this->assertTrue((bool) $approveCalls[0][1][1]);
        // As an xsd:dateTime: PropertyWare takes a bare date and then stores no
        // date at all (checked live, 2026-09-18).
        $this->assertSame('2026-08-17T00:00:00', $approveCalls[0][1][2]);
        $this->assertSame(self::OWNER_COMMENT, $approveCalls[0][1][3]);
    }

    public function test_work_order_save_does_not_re_approve_in_propertyware(): void
    {
        Http::fake();

        // A real service (constructor included, so the REST headers exist)
        // with only the SOAP client swapped for the recorder.
        $recorder = $this->soapRecorder();
        $service = Mockery::mock(PropertyWareService::class.'[initiate]');
        $service->shouldReceive('initiate')->andReturn($recorder);
        $this->app->instance(PropertyWareService::class, $service);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888006,
            'work_order_no' => 44019,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_by' => self::OWNER_ID,
            'approved_date' => '2026-08-17',
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('work_orders.update', $workOrder), [
                'work_order_no' => $workOrder->work_order_no,
                'category' => 'Electrical',
            ])
            ->assertSessionHas('success');

        $this->assertCount(0, $this->approveCalls($recorder));
        $this->assertSame('Electrical', $workOrder->fresh()->category);
    }

    public function test_non_ascii_text_is_echoed_as_escaped_utf8(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport($this->approvedSoapRow(44020, [
            'approvalComment' => 'Hopefully this is a system issue and not that it hasn’t been fixed',
        ]), [['approved' => true]], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888007,
            'work_order_no' => 44020,
            'lease_id' => 4242,
            'description' => 'Power wash the façade & the “side” wall.',
            'is_approved' => true,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('not that it hasn’t been fixed</approvalComment>', $capturedXml);
        $this->assertStringContainsString('Power wash the façade &amp; the “side” wall.</description>', $capturedXml);
        $this->assertTrue(mb_check_encoding($capturedXml, 'UTF-8'), 'The envelope must be valid UTF-8 on the wire.');
    }

    /**
     * Minimal SOAP payload for import:work-orders — building keys are read
     * unguarded, and the Service Status custom field must resolve to a real
     * service_status row.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapImportPayload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 777001,
            'number' => 4321,
            'building' => ['portfolio' => 'Portfolio A', 'abbreviation' => 'BLDG1', 'ID' => 999001],
            'category' => 'Maintenance',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'Original tenant request.',
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
        ], $overrides);
    }

    private function prepareSoapImport(array $payload): void
    {
        if (! ServiceStatus::query()->where('name', 'New')->exists()) {
            ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        }

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrders')->andReturn([$payload]);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    private function approvedWorkOrderRow(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'propertyware_id' => 777001,
            'work_order_no' => 4321,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_by' => '555001',
            'approved_date' => '2026-08-30',
        ]);
    }

    public function test_soap_import_with_a_missing_approval_comment_keeps_local_values(): void
    {
        Queue::fake();
        $workOrder = $this->approvedWorkOrderRow();

        $this->prepareSoapImport($this->soapImportPayload());

        $this->artisan('import:work-orders')->assertExitCode(0);

        $workOrder->refresh();
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
        $this->assertSame('555001', $workOrder->approved_by);
        $this->assertSame('2026-08-30', $workOrder->approved_date);
    }

    public function test_soap_import_with_a_blank_approval_comment_keeps_local_values(): void
    {
        Queue::fake();
        $workOrder = $this->approvedWorkOrderRow();

        $this->prepareSoapImport($this->soapImportPayload([
            'approvalComment' => '',
            'approvedDate' => '',
        ]));

        $this->artisan('import:work-orders')->assertExitCode(0);

        $workOrder->refresh();
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
        $this->assertSame('2026-08-30', $workOrder->approved_date);
    }

    public function test_soap_import_with_a_populated_comment_still_updates_it(): void
    {
        Queue::fake();
        $workOrder = $this->approvedWorkOrderRow();

        $this->prepareSoapImport($this->soapImportPayload([
            'approvalComment' => 'A newer note from the owner.',
            'approved' => true,
            'approvedBy' => ['ID' => 555002],
            'approvedDate' => '2026-08-31',
        ]));

        $this->artisan('import:work-orders')->assertExitCode(0);

        $workOrder->refresh();
        $this->assertSame('A newer note from the owner.', $workOrder->approval_comments);
        $this->assertSame('555002', $workOrder->approved_by);
        $this->assertSame('2026-08-31', $workOrder->approved_date);
    }

    public function test_soap_import_reads_the_plural_key_spelling_too(): void
    {
        Queue::fake();
        $workOrder = $this->approvedWorkOrderRow();

        $this->prepareSoapImport($this->soapImportPayload([
            'approvalComments' => 'Plural-key note.',
        ]));

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertSame('Plural-key note.', $workOrder->refresh()->approval_comments);
    }

    public function test_bulk_service_import_without_approval_fields_keeps_local_values(): void
    {
        Queue::fake();

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => '424250',
            'work_order_no' => 60050,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_by' => '555001',
            'approved_date' => '2026-08-30',
        ]);

        (new WorkOrderService)->handle([
            [
                'ID' => '424250',
                'number' => 60050,
                'status' => 'Open',
                'category' => 'General Maintenance',
                'type' => 'Service Request',
                'description' => 'Leaky faucet.',
                'priorityAsInt' => 0,
            ],
        ]);

        $workOrder->refresh();
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
        $this->assertSame('555001', $workOrder->approved_by);
        $this->assertSame('2026-08-30', $workOrder->approved_date);
    }
}
