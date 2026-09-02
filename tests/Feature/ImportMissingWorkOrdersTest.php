<?php

namespace Tests\Feature;

use App\Jobs\AdoptCategorizedHoaViolationJob;
use App\Jobs\GenerateWorkOrderRecommendationJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Jobs\SendTenantServiceRequestNotificationJob;
use App\Jobs\SendTenantWorkOrderIntakeEmailJob;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * import:missing-work-orders — the backstop that walks PropertyWare's REST
 * listing, diffs it against work_orders.propertyware_id and imports what is
 * missing through the SOAP-by-number path. PropertyWare is never called for
 * real: the service is a full Mockery instance, as in the other import tests,
 * so every REST page and SOAP lookup a test expects is declared up front.
 */
class ImportMissingWorkOrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two records per REST page keeps the window and --all tests to a handful
     * of rows instead of five hundred.
     */
    private const PAGE = 2;

    private MockInterface $propertyWare;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Role::findOrCreate('woc', 'web');
        ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        $this->propertyWare = Mockery::mock(PropertyWareService::class);
        $this->app->instance(PropertyWareService::class, $this->propertyWare);
    }

    /**
     * A work order the way GET /workorders lists it: lowercase id, and the
     * "2026-02-22T01:00 AM" date shape PropertyWare's REST API emits.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function restRecord(int $id, int $number, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'number' => $number,
            'createdDateTime' => $this->pwDate(now()->subHours(2)),
            'status' => 'Open',
            'completedDate' => null,
            'assignedVendors' => [],
            'customFields' => [['fieldName' => 'Service Status', 'value' => 'New']],
        ], $overrides);
    }

    private function pwDate(Carbon $at): string
    {
        return $at->format('Y-m-d\Th:i A');
    }

    /**
     * A minimal SOAP getWorkOrders payload the way PropertyWare returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapPayload(int $id, int $number, array $overrides = []): array
    {
        return array_merge([
            'ID' => $id,
            'number' => $number,
            'status' => 'Open',
            'type' => 'Maintenance',
            'category' => 'Plumbing',
            'description' => 'Leaking kitchen sink',
            'priorityAsInt' => 3,
            'createdDate' => now()->subHours(2)->toDateTimeString(),
            'building' => ['ID' => 5001, 'portfolio' => 'Portfolio A', 'abbreviation' => 'PA-01'],
            'customFields' => [['fieldName' => 'Service Status', 'value' => 'New']],
        ], $overrides);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>|null>  $pages  keyed by offset
     */
    private function fakePages(array $pages, int $times = 1): void
    {
        foreach ($pages as $offset => $page) {
            $this->propertyWare->shouldReceive('fetchWorkOrdersPage')
                ->with(self::PAGE, $offset)
                ->times($times)
                ->andReturn($page);
        }
    }

    private function neverFetches(int $offset): void
    {
        $this->propertyWare->shouldReceive('fetchWorkOrdersPage')->with(self::PAGE, $offset)->never();
    }

    private function soapReturns(int $number, mixed $result, int $times = 1): void
    {
        $this->propertyWare->shouldReceive('getWorkOrderByNumber')->with($number)->times($times)->andReturn($result);
    }

    private function soapNeverCalled(): void
    {
        $this->propertyWare->shouldNotReceive('getWorkOrderByNumber');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function sweep(array $options = []): PendingCommand
    {
        return $this->artisan('import:missing-work-orders', array_merge(['--page-size' => self::PAGE], $options));
    }

    private function activities(string $event): int
    {
        return Activity::query()->where('event', $event)->count();
    }

    private function activity(string $event): Activity
    {
        return Activity::query()->where('event', $event)->firstOrFail();
    }

    public function test_nothing_is_missing_when_every_propertyware_work_order_exists_locally(): void
    {
        WorkOrder::factory()->create(['propertyware_id' => 800001]);
        $this->fakePages([0 => [$this->restRecord(800001, 44101)]]);
        $this->soapNeverCalled();

        $this->sweep()
            ->expectsOutputToContain('Missing: 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_a_missing_fresh_open_work_order_is_imported_with_the_full_intake_automations(): void
    {
        WorkOrder::factory()->create(['propertyware_id' => 800001]);
        $this->fakePages([
            0 => [$this->restRecord(800001, 44101), $this->restRecord(800002, 44102)],
            2 => [],
        ]);
        $this->soapReturns(44102, [$this->soapPayload(800002, 44102)]);

        $this->sweep()
            ->expectsOutputToContain('imported (full)')
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();

        $imported = WorkOrder::query()->where('propertyware_id', 800002)->firstOrFail();
        $this->assertSame(44102, (int) $imported->work_order_no);

        Queue::assertPushed(
            GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $imported->id && $job->allowAutoAssign === true
        );
        Queue::assertPushed(SendOwnerServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $imported->id);
        Queue::assertPushed(AdoptCategorizedHoaViolationJob::class, fn ($job) => $job->workOrderId === $imported->id);
        Queue::assertPushed(SendTenantWorkOrderIntakeEmailJob::class, fn ($job) => $job->workOrderId === $imported->id);
        Queue::assertPushed(SendTenantServiceRequestNotificationJob::class, fn ($job) => $job->workOrderId === $imported->id);

        $this->assertSame(1, $this->activities('missing_work_orders_imported'));
        $bell = $this->activity('missing_work_orders_imported');
        $this->assertStringContainsString('#44102', $bell->properties['message']);
        $this->assertStringContainsString('1 with the intake messages', $bell->properties['message']);
        $this->assertFalse($bell->properties['read']);
    }

    public function test_a_missing_old_open_work_order_is_imported_quietly(): void
    {
        $this->fakePages([0 => [$this->restRecord(800002, 44102, [
            'createdDateTime' => $this->pwDate(now()->subDays(10)),
        ])]]);
        $this->soapReturns(44102, [$this->soapPayload(800002, 44102, [
            'createdDate' => now()->subDays(10)->toDateTimeString(),
        ])]);

        $this->sweep()
            ->expectsOutputToContain('imported (quiet)')
            ->assertSuccessful();

        $imported = WorkOrder::query()->where('propertyware_id', 800002)->firstOrFail();

        Queue::assertPushed(
            GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $imported->id && $job->allowAutoAssign === false
        );
        Queue::assertNotPushed(SendOwnerServiceRequestNotificationJob::class);
        Queue::assertNotPushed(AdoptCategorizedHoaViolationJob::class);
        Queue::assertNotPushed(SendTenantWorkOrderIntakeEmailJob::class);
        Queue::assertNotPushed(SendTenantServiceRequestNotificationJob::class);
    }

    public function test_a_missing_closed_work_order_is_imported_silently(): void
    {
        $this->fakePages([0 => [$this->restRecord(800003, 44103, [
            'status' => 'Closed',
            'completedDate' => $this->pwDate(now()->subDays(40)),
            'createdDateTime' => $this->pwDate(now()->subDays(45)),
        ])]]);
        $this->soapReturns(44103, [$this->soapPayload(800003, 44103, [
            'status' => 'Closed',
            'completedDate' => now()->subDays(40)->toDateString(),
            'createdDate' => now()->subDays(45)->toDateTimeString(),
        ])]);

        $this->sweep()
            ->expectsOutputToContain('imported (silent)')
            ->assertSuccessful();

        $this->assertDatabaseHas('work_orders', ['propertyware_id' => 800003, 'status' => 'Closed']);
        Queue::assertNothingPushed();
    }

    public function test_an_open_work_order_already_assigned_in_propertyware_skips_the_intake_automations(): void
    {
        $this->fakePages([0 => [$this->restRecord(800004, 44104, [
            'assignedVendors' => [['id' => 7001, 'name' => 'Ace Plumbing']],
        ])]]);
        $this->soapReturns(44104, [$this->soapPayload(800004, 44104)]);

        $this->sweep()
            ->expectsOutputToContain('imported (quiet)')
            ->assertSuccessful();

        $imported = WorkOrder::query()->where('propertyware_id', 800004)->firstOrFail();

        Queue::assertPushed(
            GenerateWorkOrderRecommendationJob::class,
            fn ($job) => $job->workOrderId === $imported->id && $job->allowAutoAssign === false
        );
        Queue::assertNotPushed(SendOwnerServiceRequestNotificationJob::class);
        Queue::assertNotPushed(SendTenantServiceRequestNotificationJob::class);
    }

    public function test_dry_run_reports_the_missing_work_orders_without_importing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));

        $this->fakePages([0 => [$this->restRecord(800002, 44102)]]);
        $this->soapNeverCalled();

        // One substring per console write is all the command test harness can
        // match, so the table row is pinned whole.
        $this->sweep(['--dry-run' => true])
            ->expectsTable(
                ['PW id', 'WO#', 'Created', 'Status', 'Tier', 'Result'],
                [[800002, 44102, '2026-09-03 08:00:00', 'Open', 'full', 'would import']],
            )
            ->expectsOutputToContain('[dry-run] Missing: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_a_propertyware_soap_outage_stops_the_run_and_reports_it(): void
    {
        $this->fakePages([
            0 => [$this->restRecord(800001, 44101), $this->restRecord(800002, 44102)],
        ], times: 2);
        $this->neverFetches(2);
        $this->soapReturns(44101, 'Error: could not connect to host', times: 2);
        $this->propertyWare->shouldReceive('getWorkOrderByNumber')->with(44102)->never();

        $this->sweep()
            ->expectsOutputToContain('Pending: 2')
            ->assertFailed();

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertSame(1, $this->activities('propertyware_unreachable'));

        // The outage bell is raised once per incident, not once per run.
        $this->sweep()->assertFailed();

        $this->assertSame(1, $this->activities('propertyware_unreachable'));
    }

    public function test_a_failing_import_is_counted_the_run_continues_and_the_bell_fires_once_a_day(): void
    {
        $this->fakePages([
            0 => [$this->restRecord(800001, 44101), $this->restRecord(800002, 44102)],
            2 => [],
        ], times: 2);
        $this->soapReturns(44101, [$this->soapPayload(800001, 44101)], times: 2);
        $this->soapReturns(44102, [$this->soapPayload(800002, 44102)]);

        $importer = Mockery::mock(WorkOrderService::class);
        $importer->shouldReceive('handle')->andReturnUsing(function (array $payloads) {
            if ($payloads[0]['number'] === 44101) {
                throw new RuntimeException('SQLSTATE[23000]: Column not found: service_status_id');
            }

            return [WorkOrder::factory()->create(['propertyware_id' => 800002, 'work_order_no' => 44102])->id];
        });
        $this->app->instance(WorkOrderService::class, $importer);

        $this->sweep()
            ->expectsOutputToContain('Failed: 1')
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();

        $this->assertSame(1, $this->activities('work_order_import_failed'));
        $bell = $this->activity('work_order_import_failed');
        $this->assertSame(44101, $bell->properties['work_order_no']);
        $this->assertSame('exception', $bell->properties['reason']);
        $this->assertStringContainsString('Column not found', $bell->properties['message']);

        // Same failure on the next run: no second bell within the day.
        $this->sweep()
            ->expectsOutputToContain('Failed: 1')
            ->assertSuccessful();

        $this->assertSame(1, $this->activities('work_order_import_failed'));
    }

    public function test_the_limit_caps_soap_attempts_and_reports_the_rest_as_pending(): void
    {
        $this->fakePages([
            0 => [$this->restRecord(800001, 44101), $this->restRecord(800002, 44102)],
            2 => [$this->restRecord(800003, 44103)],
        ]);
        $this->soapReturns(44101, [$this->soapPayload(800001, 44101)]);
        $this->soapReturns(44102, [$this->soapPayload(800002, 44102)]);
        $this->propertyWare->shouldReceive('getWorkOrderByNumber')->with(44103)->never();

        $this->sweep(['--limit' => 2])
            ->expectsOutputToContain('Imported: 2')
            ->expectsOutputToContain('Pending: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_orders', 2);
        $this->assertStringContainsString('1 more still pending', $this->activity('missing_work_orders_imported')->properties['message']);
    }

    public function test_the_day_window_stops_walking_once_a_page_is_older_than_the_cutoff(): void
    {
        $this->fakePages([0 => [
            $this->restRecord(800001, 44101),
            $this->restRecord(800002, 44102, ['createdDateTime' => $this->pwDate(now()->subDays(10))]),
        ]]);
        $this->neverFetches(2);
        $this->soapNeverCalled();

        $this->sweep(['--dry-run' => true, '--days' => 7])
            ->expectsOutputToContain('[dry-run] Missing: 2')
            ->expectsOutputToContain('[dry-run] Pages: 1')
            ->assertSuccessful();
    }

    public function test_all_ignores_the_day_window_and_walks_to_the_last_page(): void
    {
        $old = ['createdDateTime' => $this->pwDate(now()->subDays(400))];
        $this->fakePages([
            0 => [$this->restRecord(800001, 44101, $old), $this->restRecord(800002, 44102, $old)],
            2 => [$this->restRecord(800003, 44103, $old)],
        ]);
        $this->neverFetches(4);
        $this->soapNeverCalled();

        $this->sweep(['--dry-run' => true, '--all' => true])
            ->expectsOutputToContain('[dry-run] Missing: 3')
            ->expectsOutputToContain('[dry-run] Pages: 2')
            ->assertSuccessful();
    }

    public function test_a_failed_rest_page_is_skipped_and_counted(): void
    {
        $this->fakePages([
            0 => null,
            2 => [$this->restRecord(800003, 44103)],
        ]);
        $this->soapReturns(44103, [$this->soapPayload(800003, 44103)]);

        $this->sweep()
            ->expectsOutputToContain('Pages failed: 1')
            ->expectsOutputToContain('Imported: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('work_orders', ['propertyware_id' => 800003]);
    }

    public function test_the_run_fails_when_propertyware_rest_never_answers(): void
    {
        $this->fakePages([0 => null, 2 => null]);
        $this->soapNeverCalled();

        $this->sweep(['--max-pages' => 2])
            ->expectsOutputToContain('Pages failed: 2')
            ->assertFailed();
    }

    public function test_work_orders_newer_than_the_grace_period_are_left_to_the_fast_lane(): void
    {
        $this->fakePages([0 => [$this->restRecord(800002, 44102, [
            'createdDateTime' => $this->pwDate(now()->subMinutes(5)),
        ])]]);
        $this->soapNeverCalled();

        $this->sweep()
            ->expectsOutputToContain('Too fresh: 1')
            ->expectsOutputToContain('Missing: 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_a_number_soap_cannot_find_is_reported(): void
    {
        $this->fakePages([0 => [$this->restRecord(800002, 44102)]]);
        $this->soapReturns(44102, []);

        $this->sweep()
            ->expectsOutputToContain('Not in SOAP: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_orders', 0);
        $bell = $this->activity('work_order_import_failed');
        $this->assertSame('not_in_soap', $bell->properties['reason']);
        $this->assertSame(800002, $bell->properties['propertyware_id']);
    }

    public function test_an_unparseable_created_date_still_imports_quietly(): void
    {
        $this->fakePages([0 => [$this->restRecord(800002, 44102, ['createdDateTime' => 'n/a'])]]);
        $this->soapReturns(44102, [$this->soapPayload(800002, 44102)]);

        $this->sweep()
            ->expectsOutputToContain('Undated: 1')
            ->expectsOutputToContain('imported (quiet)')
            ->assertSuccessful();

        $this->assertDatabaseHas('work_orders', ['propertyware_id' => 800002]);
        Queue::assertPushed(GenerateWorkOrderRecommendationJob::class);
        Queue::assertNotPushed(SendTenantServiceRequestNotificationJob::class);
    }

    public function test_a_soap_payload_for_a_different_work_order_is_reported_as_a_mismatch(): void
    {
        $this->fakePages([0 => [$this->restRecord(800002, 44102)]]);
        $this->soapReturns(44102, [$this->soapPayload(999999, 44102)]);

        $this->sweep()
            ->expectsOutputToContain('Mismatch: 1')
            ->expectsOutputToContain('Imported: 0')
            ->assertSuccessful();

        $this->assertSame('id_mismatch', $this->activity('work_order_import_failed')->properties['reason']);
        $this->assertSame(0, $this->activities('missing_work_orders_imported'));
    }

    public function test_the_service_parses_propertyware_date_shapes(): void
    {
        $this->assertSame('2026-02-22 01:00:00', PropertyWareService::parseDate('2026-02-22T01:00 AM')?->toDateTimeString());
        $this->assertSame('2026-02-22 13:05:00', PropertyWareService::parseDate('2026-02-22T01:05 PM')?->toDateTimeString());
        $this->assertSame('2026-09-01 09:15:00', PropertyWareService::parseDate('2026-09-01T09:15:00')?->toDateTimeString());
        $this->assertNull(PropertyWareService::parseDate('n/a'));
        $this->assertNull(PropertyWareService::parseDate(''));
        $this->assertNull(PropertyWareService::parseDate(null));
    }
}
