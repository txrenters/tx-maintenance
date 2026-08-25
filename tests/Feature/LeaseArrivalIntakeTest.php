<?php

namespace Tests\Feature;

use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\ServiceStatus;
use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AutomatedMessageLogService;
use App\Services\MicrosoftGraphMailService;
use App\Services\PropertyWareService;
use App\Services\TenantWorkOrderEmailSender;
use App\Services\WorkOrderLeaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PropertyWare's website-request intake creates a work order on an occupied
 * home without its lease; the lease only appears on the work order's next
 * save. The importer reads the work order before that save, the vacant-home
 * skip mutes every automated message, and the one-shot intake texts were
 * lost for good (WO#43937). These tests cover the staff alert at intake, the
 * intake re-run when the lease arrives, and the lease refresh after a vendor
 * assignment.
 */
class LeaseArrivalIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR_IDS_XML = '<vendorIDs xsi:type="soapenc:Array" xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/"><vendorID xsi:type="xsd:long">246120584</vendorID></vendorIDs>';

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        // The importer assigns the requester's login the tenant role.
        foreach (['woc', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        User::factory()->create()->assignRole('woc');
    }

    /**
     * A SOAP getWorkOrders row the way PropertyWare returns a website request:
     * the tenant is the requester, but no lease is attached.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 8190656705,
            'number' => 43937,
            'status' => 'Open',
            'type' => 'Service Request',
            'category' => 'Water leak',
            'description' => 'Recurring wet spot on the carpet in the master closet.',
            'priorityAsInt' => 2,
            'source' => 'Website',
            'createdDate' => now()->subHours(2)->toIso8601String(),
            'building' => ['ID' => 2469888018, 'portfolio' => 'CRUZ,CATHE', 'abbreviation' => '2939ASPENPAR'],
            'requestedByContact' => [
                'ID' => 3680043043,
                'firstName' => 'Rodrigo',
                'lastName' => 'Garcia Quintanilla',
                'email' => 'rodrigo@example.com',
                'mobilePhone' => '(415) 374-9801',
                'homePhone' => '(415) 374-9801',
                'gender' => 1,
                'namedOnLease' => true,
                'dirty' => false,
            ],
            'customFields' => [['fieldName' => 'Service Status', 'value' => 'New']],
        ], $overrides);
    }

    /**
     * The same work order after PropertyWare attached the lease.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function withLease(array $overrides = []): array
    {
        return $this->payload(array_merge(['lease' => ['ID' => 2860285968, 'tenants' => []]], $overrides));
    }

    /**
     * Stub the scheduled import's SOAP fetch. Artisan keeps one command
     * instance per test, so a single mock must serve every run: its rows are
     * returned in order, one set per run.
     *
     * @param  array<string, mixed>  ...$payloads
     */
    private function fakeScheduledImports(array ...$payloads): void
    {
        $this->mock(PropertyWareService::class)
            ->shouldReceive('getWorkOrders')
            ->times(count($payloads))
            ->andReturn(...array_map(fn (array $payload): array => [$payload], $payloads));
    }

    /**
     * @param  array<string, mixed>|null  $payload  a single-run shortcut; null when fakeScheduledImports() was already called
     */
    private function runScheduledImport(?array $payload = null): void
    {
        if ($payload !== null) {
            $this->fakeScheduledImports($payload);
        }

        $this->artisan('import:work-orders')->assertSuccessful();
    }

    private function importedWorkOrder(): WorkOrder
    {
        return WorkOrder::query()->where('propertyware_id', 8190656705)->firstOrFail();
    }

    private function alertCount(): int
    {
        return Activity::query()->where('event', WorkOrderLeaseService::EVENT_LEASE_MISSING)->count();
    }

    /**
     * A lease-less website request as the importer stores it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function leaselessWorkOrder(array $overrides = []): WorkOrder
    {
        $tenant = Tenants::query()->create([
            'first_name' => 'Rodrigo',
            'last_name' => 'Garcia Quintanilla',
            'email' => 'rodrigo@example.com',
            'mobile_phone' => '4153749801',
            'user_id' => User::factory()->create()->id,
        ]);

        return WorkOrder::factory()->create(array_merge([
            'propertyware_id' => 8190656705,
            'work_order_no' => 43937,
            'status' => 'Open',
            'type' => 'Service Request',
            'category' => 'Water leak',
            'source' => 'Website',
            'lease_id' => null,
            'tenant_id' => $tenant->id,
            'building_id' => 2469888018,
            'portfolio_id' => 2297495562,
            'location' => 'CRUZ,CATHE | 2939ASPENPAR',
            'created_date' => now()->subHours(2),
        ], $overrides));
    }

    /**
     * A PropertyWare service whose SOAP calls are stubbed: the save succeeds
     * and the by-number lookup returns the given rows.
     *
     * @param  array<int, array<string, mixed>>|null  $lookupRows  null = the lookup must not happen
     */
    private function propertyWareWithSave(?array $lookupRows, ?string &$capturedXml = null): PropertyWareService
    {
        return $this->partialMock(PropertyWareService::class, function ($mock) use ($lookupRows, &$capturedXml) {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(function (string $xml) use (&$capturedXml): bool {
                    $capturedXml = $xml;

                    return true;
                })
                ->andReturn(['success' => true, 'response' => '<ok/>']);

            if ($lookupRows === null) {
                $mock->shouldReceive('getWorkOrderByNumber')->never();
            } else {
                $mock->shouldReceive('getWorkOrderByNumber')->once()->with(43937)->andReturn($lookupRows);
            }
        });
    }

    private function fakeGraph(int $expectedSends): void
    {
        $mock = Mockery::mock(MicrosoftGraphMailService::class);
        $mock->shouldReceive('sendMail')
            ->times($expectedSends)
            ->andReturn([
                'graph_message_id' => 'msg-1',
                'graph_conversation_id' => 'conv-1',
                'internet_message_id' => '<msg-1@example.com>',
            ]);

        $this->app->instance(MicrosoftGraphMailService::class, $mock);
    }

    public function test_a_website_request_imported_without_a_lease_alerts_staff(): void
    {
        Queue::fake();

        $this->runScheduledImport($this->payload());

        $workOrder = $this->importedWorkOrder();
        $this->assertNull($workOrder->lease_id);
        $this->assertNotNull($workOrder->tenant_id);

        $alert = Activity::query()->where('event', WorkOrderLeaseService::EVENT_LEASE_MISSING)->first();
        $this->assertNotNull($alert);
        $this->assertSame($workOrder->id, $alert->properties['work_order_id']);
        $this->assertSame('Website', $alert->properties['source']);
        $this->assertFalse($alert->properties['read']);
        $this->assertStringContainsString('without a lease on file', $alert->properties['message']);

        // The muted intake texts show in the ledger with the reason, one per audience.
        $skipped = Activity::query()
            ->where('log_name', AutomatedMessageLogService::LOG_NAME)
            ->where('properties->not_texted_reason', 'no_lease_on_file')
            ->pluck('description')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['owner:sms', 'tenant:sms'], $skipped);
    }

    public function test_a_request_imported_with_its_lease_raises_no_alert(): void
    {
        Queue::fake();

        $this->runScheduledImport($this->withLease());

        $this->assertSame(0, $this->alertCount());
    }

    public function test_a_request_without_a_tenant_contact_raises_no_alert(): void
    {
        Queue::fake();

        $this->runScheduledImport($this->payload(['requestedByContact' => null]));

        $this->assertNull($this->importedWorkOrder()->tenant_id);
        $this->assertSame(0, $this->alertCount());
    }

    public function test_a_turnover_without_a_lease_is_muted_on_purpose_and_not_alerted(): void
    {
        Queue::fake();

        $this->runScheduledImport($this->payload(['type' => 'Turnover']));

        $this->assertSame(0, $this->alertCount());
    }

    public function test_a_tenant_portal_request_is_exempt_and_not_alerted(): void
    {
        Queue::fake();

        $this->runScheduledImport($this->payload(['source' => 'Tenant Portal']));

        $this->assertSame(0, $this->alertCount());
    }

    public function test_the_lease_arriving_on_a_later_sync_sends_the_missed_intake_messages(): void
    {
        $this->fakeScheduledImports($this->payload(), $this->withLease());

        Queue::fake();
        $this->runScheduledImport();
        $workOrder = $this->importedWorkOrder();

        Queue::fake();
        $this->runScheduledImport();

        $workOrder->refresh();
        $this->assertSame(2860285968, (int) $workOrder->lease_id);

        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendTenantWorkOrderIntakeEmailJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        // Never a second (paid) AI classification.
        Queue::assertNotPushed(GenerateWorkOrderRecommendationJob::class);

        // The staff alert is resolved now that the condition no longer holds.
        $alert = Activity::query()->where('event', WorkOrderLeaseService::EVENT_LEASE_MISSING)->firstOrFail();
        $this->assertTrue($alert->properties['read']);
        $this->assertArrayHasKey('resolved_at', $alert->properties);
    }

    public function test_a_sync_that_brings_no_lease_change_dispatches_nothing(): void
    {
        $this->fakeScheduledImports($this->withLease(), $this->withLease());

        Queue::fake();
        $this->runScheduledImport();

        Queue::fake();
        $this->runScheduledImport();

        Queue::assertNothingPushed();
    }

    public function test_a_lease_arriving_on_an_old_work_order_does_not_resend_intake(): void
    {
        $created = now()->subDays(WorkOrderLeaseService::MAX_AGE_DAYS + 3)->toIso8601String();
        $this->fakeScheduledImports(
            $this->payload(['createdDate' => $created]),
            $this->withLease(['createdDate' => $created]),
        );

        Queue::fake();
        $this->runScheduledImport();

        Queue::fake();
        $this->runScheduledImport();

        $this->assertNotNull($this->importedWorkOrder()->lease_id);
        Queue::assertNothingPushed();
    }

    public function test_a_lease_arriving_on_a_closed_work_order_does_not_resend_intake(): void
    {
        $this->fakeScheduledImports($this->payload(), $this->withLease(['status' => 'Closed']));

        Queue::fake();
        $this->runScheduledImport();

        Queue::fake();
        $this->runScheduledImport();

        Queue::assertNothingPushed();
    }

    public function test_a_lease_arriving_after_intake_already_ran_dispatches_nothing(): void
    {
        $this->fakeScheduledImports($this->payload(), $this->withLease());

        Queue::fake();
        $this->runScheduledImport();

        WorkOrder::query()->whereKey($this->importedWorkOrder()->id)->update([
            'tenant_service_request_notified_at' => now(),
            'owner_service_request_notified_at' => now(),
        ]);

        Queue::fake();
        $this->runScheduledImport();

        Queue::assertNothingPushed();
    }

    public function test_the_import_button_also_reacts_to_the_lease_arriving(): void
    {
        $staff = User::role('woc')->firstOrFail();

        Queue::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('getWorkOrderByNumber')->with(43937)->andReturn([$this->payload()]);
        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 43937])
            ->assertSessionHasNoErrors();

        $workOrder = $this->importedWorkOrder();
        $this->assertSame(1, $this->alertCount());

        Queue::fake();
        $this->mock(PropertyWareService::class)
            ->shouldReceive('getWorkOrderByNumber')->with(43937)->andReturn([$this->withLease()]);
        $this->actingAs($staff)
            ->post(route('work_orders.import'), ['work_order_no' => 43937])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        $this->assertTrue(Activity::query()->where('event', WorkOrderLeaseService::EVENT_LEASE_MISSING)->firstOrFail()->properties['read']);
    }

    public function test_assigning_a_vendor_pulls_the_lease_propertyware_attached_on_save(): void
    {
        Queue::fake();
        $workOrder = $this->leaselessWorkOrder();

        $this->propertyWareWithSave([$this->withLease()])
            ->changeWorkOrderVendors($workOrder, self::VENDOR_IDS_XML);

        $this->assertSame(2860285968, (int) $workOrder->lease_id);
        $this->assertSame(2860285968, (int) $workOrder->fresh()->lease_id);

        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendTenantWorkOrderIntakeEmailJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $workOrder->id);
    }

    public function test_a_save_that_still_returns_no_lease_leaves_the_work_order_muted(): void
    {
        Queue::fake();
        $workOrder = $this->leaselessWorkOrder();

        $this->propertyWareWithSave([$this->payload()])
            ->changeWorkOrderVendors($workOrder, self::VENDOR_IDS_XML);

        $this->assertNull($workOrder->fresh()->lease_id);
        Queue::assertNothingPushed();
    }

    public function test_a_work_order_that_already_has_its_lease_is_not_looked_up_again(): void
    {
        Queue::fake();
        $workOrder = $this->leaselessWorkOrder(['lease_id' => 111]);

        $this->propertyWareWithSave(null)
            ->changeWorkOrderVendors($workOrder, self::VENDOR_IDS_XML);

        $this->assertSame(111, (int) $workOrder->fresh()->lease_id);
        Queue::assertNothingPushed();
    }

    public function test_the_vendor_sync_sends_the_source_back_to_propertyware(): void
    {
        Queue::fake();
        $workOrder = $this->leaselessWorkOrder(['source' => 'Website']);
        $xml = null;

        $this->propertyWareWithSave([$this->payload()], $xml)
            ->changeWorkOrderVendors($workOrder, self::VENDOR_IDS_XML);

        $this->assertStringContainsString('<source xsi:type="xsd:string">Website</source>', $xml);
        $this->assertStringContainsString('<vendorID xsi:type="xsd:long">246120584</vendorID>', $xml);
    }

    public function test_the_vendor_sync_omits_a_blank_source(): void
    {
        Queue::fake();
        $workOrder = $this->leaselessWorkOrder(['source' => null]);
        $xml = null;

        $this->propertyWareWithSave([$this->payload()], $xml)
            ->changeWorkOrderVendors($workOrder, self::VENDOR_IDS_XML);

        $this->assertStringNotContainsString('<source', $xml);
    }

    public function test_the_intake_email_is_sent_once_even_when_intake_is_re_run(): void
    {
        config(['services.work_order.tenant_intake_email' => true]);
        $this->fakeGraph(1);

        $workOrder = WorkOrder::factory()->create([
            'status' => 'Open',
            'work_order_no' => 43937,
            'tenant_id' => Tenants::query()->create([
                'first_name' => 'Rodrigo',
                'last_name' => 'Garcia Quintanilla',
                'email' => 'rodrigo@example.com',
                'mobile_phone' => '4153749801',
                'user_id' => User::factory()->create()->id,
            ])->id,
        ]);

        $sender = app(TenantWorkOrderEmailSender::class);

        $this->assertTrue($sender->sendIntakeConfirmation($workOrder));
        $this->assertFalse($sender->sendIntakeConfirmation($workOrder));
        $this->assertSame(1, TenantEmailNotification::query()->count());
    }
}
