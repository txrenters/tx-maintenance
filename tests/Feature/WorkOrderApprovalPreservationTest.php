<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Approval comments are entered only in PropertyWare; a wiped value is
 * unrecoverable. Assigning a vendor used to blank them there (full-replace
 * SOAP envelope without the field) and the next import then blanked them
 * locally too (WO#44014).
 */
class WorkOrderApprovalPreservationTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_COMMENT = 'We just opened a ticket with the home warranty company.';

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
     * captures the outgoing SOAP envelope, `getWorkOrder` serves the given
     * REST snapshot, `initiate` hands back the recorder. (Http::fake cannot
     * intercept either — execute() is raw curl and initiate() news up a
     * \SoapClient.)
     *
     * @param  array<string, mixed>|false  $pwSnapshot
     */
    private function stubPropertyWareTransport(array|false $pwSnapshot, ?string &$capturedXml, object $soapRecorder): PropertyWareService
    {
        return $this->partialMock(PropertyWareService::class, function ($mock) use ($pwSnapshot, &$capturedXml, $soapRecorder) {
            $mock->shouldReceive('execute')->andReturnUsing(function (string $xml) use (&$capturedXml) {
                $capturedXml = $xml;

                return ['success' => true, 'error' => null, 'message' => 'ok'];
            });
            $mock->shouldReceive('getWorkOrder')->andReturn($pwSnapshot);
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

    public function test_vendor_assign_carries_the_approval_comment_into_propertyware(): void
    {
        Queue::fake();

        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $this->stubPropertyWareTransport([
            'approvalComment' => self::OWNER_COMMENT,
            'approvedDate' => '2026-08-30',
            'approved' => true,
        ], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888001,
            'work_order_no' => 44014,
            'lease_id' => 4242,
            'is_approved' => true,
            'approval_comments' => self::OWNER_COMMENT,
            'approved_date' => '2026-08-30',
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
            ->assertSessionHas('success');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">'.self::OWNER_COMMENT.'</approvalComment>', $capturedXml);

        $approveCalls = $this->approveCalls($recorder);
        $this->assertCount(1, $approveCalls);
        $this->assertSame(self::OWNER_COMMENT, $approveCalls[0][1][3]);

        $this->assertSame(self::OWNER_COMMENT, $workOrder->refresh()->approval_comments);
    }

    public function test_vendor_assign_of_an_unapproved_work_order_still_carries_the_comment(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport([
            'approvalComment' => self::OWNER_COMMENT,
            'approved' => false,
        ], $capturedXml, $recorder);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888002,
            'work_order_no' => 44015,
            'lease_id' => 4242,
            'is_approved' => false,
            'approval_comments' => self::OWNER_COMMENT,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">'.self::OWNER_COMMENT.'</approvalComment>', $capturedXml);

        $this->assertCount(0, $this->approveCalls($recorder));

        $workOrder->refresh();
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
        $this->assertFalse((bool) $workOrder->is_approved);
    }

    public function test_vendor_assign_restores_a_stale_local_approval_from_propertyware(): void
    {
        $capturedXml = null;
        $recorder = $this->soapRecorder();
        $service = $this->stubPropertyWareTransport([
            'approvalComment' => self::OWNER_COMMENT,
            'approvedDate' => '2026-08-25',
            'approved' => true,
        ], $capturedXml, $recorder);

        // The import hasn't caught up: PropertyWare holds the comment, we don't.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 888003,
            'work_order_no' => 44016,
            'lease_id' => 4242,
            'is_approved' => true,
            'approval_comments' => null,
            'approved_date' => null,
        ]);

        $service->changeWorkOrderVendors($workOrder, '');

        $this->assertNotNull($capturedXml);
        $this->assertStringContainsString('<approvalComment xsi:type="xsd:string">'.self::OWNER_COMMENT.'</approvalComment>', $capturedXml);

        $approveCalls = $this->approveCalls($recorder);
        $this->assertCount(1, $approveCalls);
        $this->assertSame('2026-08-25', $approveCalls[0][1][2]);
        $this->assertSame(self::OWNER_COMMENT, $approveCalls[0][1][3]);

        $workOrder->refresh();
        $this->assertSame(self::OWNER_COMMENT, $workOrder->approval_comments);
        $this->assertSame('2026-08-25', $workOrder->approved_date);
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
