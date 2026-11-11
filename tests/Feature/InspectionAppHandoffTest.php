<?php

namespace Tests\Feature;

use App\Jobs\PushWorkOrderToInspectionApp;
use App\Models\Building;
use App\Models\InspectionAppHandoff;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\InspectionApp\HandoffRule;
use App\Services\InspectionApp\HandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Work orders for our own crews (THMP, Crystal Creek Air, Crystal Creek
 * Pest) are handed to the technicians' inspection app the moment one of
 * those vendors lands on them: the rule that picks them, the outbox row
 * that records every attempt, the signed POST to the app's gateway, and
 * the sweep that retries and re-sends changed work orders.
 */
class InspectionAppHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'the-secret-half-of-the-key-43-chars-long-xx';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.inspection_app.url' => 'https://inspection-api.example.test',
            'services.inspection_app.token' => 'trk_live_abc123def456.'.self::SECRET,
            'services.inspection_app.handoff' => true,
            'services.inspection_app.handoff_vendor_ids' => '246120584,5466750978,4329930755',
            'services.inspection_app.handoff_vendor_names' => 'Texas Home Maintenance Pros|Crystal Creek Air, LLC|Crystal Creek Pest Control LLC',
            'services.inspection_app.handoff_skip_vendor_ids' => '4802084865',
        ]);

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function vendor(string $propertywareId, string $name): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => $propertywareId,
            'name' => $name,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function thmp(): Vendor
    {
        return $this->vendor(Vendor::THMP_PROPERTYWARE_ID, Vendor::THMP_NAME);
    }

    /**
     * @param  list<Vendor>  $vendors
     * @param  array<string, mixed>  $attributes
     */
    private function workOrderWith(array $vendors, array $attributes = []): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create($attributes);

        foreach ($vendors as $vendor) {
            DB::table('work_order_vendors')->insert([
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $workOrder->fresh(['vendors']);
    }

    // ---- The rule -------------------------------------------------------

    public function test_thmp_by_propertyware_id_qualifies_whatever_its_name_reads(): void
    {
        $workOrder = $this->workOrderWith([$this->vendor(Vendor::THMP_PROPERTYWARE_ID, 'THMP (renamed)')]);

        $this->assertTrue(app(HandoffRule::class)->qualifies($workOrder));
    }

    public function test_both_crystal_creeks_qualify_by_id_and_a_renamed_row_still_qualifies_by_name(): void
    {
        $pest = $this->workOrderWith([$this->vendor('4329930755', 'CC Pest (renamed)')]);
        $air = $this->workOrderWith([$this->vendor('5466750978', 'CCA (renamed)')]);
        $recreated = $this->workOrderWith([$this->vendor('9999999999', '  crystal creek air,  llc ')]);

        $rule = app(HandoffRule::class);

        $this->assertTrue($rule->qualifies($pest));
        $this->assertTrue($rule->qualifies($air));
        $this->assertTrue($rule->qualifies($recreated));
    }

    public function test_an_outside_vendor_no_vendor_or_crystal_creek_construction_does_not_qualify(): void
    {
        $outside = $this->workOrderWith([$this->vendor('111', 'Southwinds Electric LLC')]);
        $none = $this->workOrderWith([]);
        $construction = $this->workOrderWith([$this->vendor('1373863948', 'Crystal Creek Construction, LLC')]);

        $rule = app(HandoffRule::class);

        $this->assertFalse($rule->qualifies($outside));
        $this->assertFalse($rule->qualifies($none));
        $this->assertFalse($rule->qualifies($construction));
    }

    public function test_thmp_tagged_onto_an_sfa_work_order_is_skipped(): void
    {
        $workOrder = $this->workOrderWith([$this->thmp(), $this->vendor('4802084865', 'Jimmie')]);

        $this->assertFalse(app(HandoffRule::class)->qualifies($workOrder));
    }

    public function test_thmp_beside_an_outside_vendor_still_qualifies(): void
    {
        $workOrder = $this->workOrderWith([$this->thmp(), $this->vendor('111', 'Southwinds Electric LLC')]);

        $this->assertTrue(app(HandoffRule::class)->qualifies($workOrder));
    }

    // ---- consider(): the trigger ----------------------------------------

    public function test_consider_records_an_outbox_row_and_dispatches_the_push_at_once(): void
    {
        Queue::fake();
        $workOrder = $this->workOrderWith([$this->thmp()]);

        $row = app(HandoffService::class)->consider($workOrder->id, 'vendor_change');

        $this->assertNotNull($row);
        $this->assertDatabaseHas('inspection_app_handoffs', [
            'work_order_id' => $workOrder->id,
            'status' => InspectionAppHandoff::STATUS_PENDING,
            'reason' => 'vendor_change',
        ]);
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, fn ($job) => $job->handoffId === $row->id);
    }

    public function test_consider_does_nothing_when_the_switch_is_off_or_the_vendor_is_outside(): void
    {
        Queue::fake();
        $thmp = $this->workOrderWith([$this->thmp()]);
        $outside = $this->workOrderWith([$this->vendor('111', 'Southwinds Electric LLC')]);

        config(['services.inspection_app.handoff' => false]);
        $this->assertNull(app(HandoffService::class)->consider($thmp->id, 'vendor_change'));

        config(['services.inspection_app.handoff' => true]);
        $this->assertNull(app(HandoffService::class)->consider($outside->id, 'vendor_change'));

        $this->assertDatabaseCount('inspection_app_handoffs', 0);
        Queue::assertNothingPushed();
    }

    public function test_consider_leaves_a_closed_work_order_alone_unless_it_was_already_handed_off(): void
    {
        Queue::fake();
        $closed = $this->workOrderWith([$this->thmp()], ['status' => 'Closed']);

        $this->assertNull(app(HandoffService::class)->consider($closed->id, 'import'));
        $this->assertDatabaseCount('inspection_app_handoffs', 0);

        InspectionAppHandoff::query()->create([
            'work_order_id' => $closed->id,
            'status' => InspectionAppHandoff::STATUS_SENT,
            'sent_at' => now()->subDay(),
        ]);

        $this->assertNotNull(app(HandoffService::class)->consider($closed->id, 'status_sync'));
        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
    }

    public function test_consider_twice_keeps_one_row(): void
    {
        Queue::fake();
        $workOrder = $this->workOrderWith([$this->thmp()]);

        app(HandoffService::class)->consider($workOrder->id, 'import');
        app(HandoffService::class)->consider($workOrder->id, 'vendor_change');

        $this->assertDatabaseCount('inspection_app_handoffs', 1);
    }

    // ---- push(): the signed POST ----------------------------------------

    public function test_push_sends_the_signed_work_order_and_records_the_answer(): void
    {
        Http::fake(['inspection-api.example.test/*' => Http::response(['id' => 'insp-uuid-1'], 201)]);

        $building = Building::query()->create([
            'propertyware_id' => '465764359',
            'name' => '123 Demo St',
            'address' => '123 Demo St',
            'city' => 'Houston',
            'state_region' => 'TX',
            'postal_code' => '77001',
            'latitude' => 29.76,
            'longitude' => -95.36,
            'active' => true,
        ]);
        $tenant = Tenants::factory()->create([
            'first_name' => 'Pat',
            'last_name' => 'Tenant',
            'email' => 'pat@example.com',
            'mobile_phone' => '5125550100',
        ]);
        $workOrder = $this->workOrderWith([$this->thmp(), $this->vendor('111', 'Southwinds Electric LLC')], [
            'work_order_no' => 43761,
            'propertyware_id' => 'PW-43761',
            'building_id' => $building->propertyware_id,
            'category' => 'HVAC ',
            'priority' => 'High',
            'is_emergency' => true,
            'zone' => '2',
            'description' => 'AC not cooling',
            'jobber_job_gid' => 'Z2lkOi8vSm9iYmVyL0pvYi8x',
            'jobber_web_uri' => 'https://secure.getjobber.com/jobs/1',
        ]);
        DB::table('work_order_tenants')->insert([
            'work_order_id' => $workOrder->id,
            'tenant_id' => $tenant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id, 'reason' => 'vendor_change']);

        app(HandoffService::class)->push($row->id);

        Http::assertSent(function (Request $request) use ($workOrder) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://inspection-api.example.test/api/v1/gateway/work-orders', $request->url());
            $this->assertSame('trk_live_abc123def456.'.self::SECRET, $request->header('x-api-key')[0]);
            $this->assertArrayNotHasKey('Authorization', $request->headers());

            $timestamp = $request->header('x-timestamp')[0];
            $this->assertEqualsWithDelta(now()->getTimestamp(), (int) $timestamp, 5);
            $signed = "POST\n/api/v1/gateway/work-orders\n".$timestamp."\n".hash('sha256', $request->body());
            $this->assertSame(hash_hmac('sha256', $signed, self::SECRET), $request->header('x-signature')[0]);

            $body = json_decode($request->body(), true);
            $this->assertSame('tx-maintenance:work-order:'.$workOrder->id, $body['idempotency_key']);
            $this->assertSame(43761, $body['work_order']['number']);
            $this->assertSame('PW-43761', $body['work_order']['propertyware_id']);
            $this->assertSame('HVAC', $body['work_order']['category']);
            $this->assertSame('High', $body['work_order']['priority']);
            $this->assertTrue($body['work_order']['is_emergency']);
            $this->assertSame('2', $body['work_order']['zone']);
            $this->assertSame('Open', $body['work_order']['status']);
            $this->assertSame('New', $body['work_order']['service_status']);
            $this->assertSame('Z2lkOi8vSm9iYmVyL0pvYi8x', $body['work_order']['jobber']['job_gid']);
            $this->assertSame('465764359', $body['property']['propertyware_building_id']);
            $this->assertSame('123 Demo St', $body['property']['address']);
            $this->assertSame(29.76, $body['property']['latitude']);
            $this->assertTrue($body['occupied']);
            $this->assertSame([['name' => 'Pat Tenant', 'phone' => '+15125550100', 'email' => 'pat@example.com']], $body['tenants']);
            $this->assertSame(['246120584', '111'], array_column($body['vendors'], 'propertyware_id'));
            $this->assertSame([true, false], array_column($body['vendors'], 'in_house'));

            return true;
        });

        $row->refresh();
        $this->assertSame(InspectionAppHandoff::STATUS_SENT, $row->status);
        $this->assertSame('insp-uuid-1', $row->external_id);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->sent_at);
        $this->assertNotNull($row->payload_hash);
        $this->assertNull($row->last_error);
    }

    public function test_a_refusal_marks_the_row_failed_with_the_apps_words_and_does_not_retry(): void
    {
        Http::fake(['inspection-api.example.test/*' => Http::response([
            'statusCode' => 422,
            'code' => 'WORK_ORDER_PROPERTY_UNKNOWN',
            'message' => 'No building with that Propertyware id.',
            'requestId' => 'req-1',
        ], 422)]);
        $workOrder = $this->workOrderWith([$this->thmp()]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id]);

        app(HandoffService::class)->push($row->id);

        $row->refresh();
        $this->assertSame(InspectionAppHandoff::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('WORK_ORDER_PROPERTY_UNKNOWN', $row->last_error);
        $this->assertStringContainsString('No building with that Propertyware id.', $row->last_error);
        $this->assertStringContainsString('req-1', $row->last_error);
        $this->assertNull($row->sent_at);
    }

    public function test_an_outage_keeps_the_row_pending_and_throws_so_the_queue_retries(): void
    {
        Http::fake(['inspection-api.example.test/*' => Http::response('Bad Gateway', 502)]);
        $workOrder = $this->workOrderWith([$this->thmp()]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id]);

        try {
            app(HandoffService::class)->push($row->id);
            $this->fail('A 502 must be thrown so the job retries.');
        } catch (RuntimeException) {
        }

        $row->refresh();
        $this->assertSame(InspectionAppHandoff::STATUS_PENDING, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('502', $row->last_error);
    }

    public function test_push_sends_nothing_when_the_switch_is_off_or_the_work_order_no_longer_qualifies(): void
    {
        Http::fake();
        $thmp = $this->workOrderWith([$this->thmp()]);
        $outside = $this->workOrderWith([$this->vendor('111', 'Southwinds Electric LLC')]);
        $offRow = InspectionAppHandoff::query()->create(['work_order_id' => $thmp->id]);
        $outsideRow = InspectionAppHandoff::query()->create(['work_order_id' => $outside->id]);

        config(['services.inspection_app.handoff' => false]);
        app(HandoffService::class)->push($offRow->id);
        $this->assertSame(InspectionAppHandoff::STATUS_PENDING, $offRow->fresh()->status);

        config(['services.inspection_app.handoff' => true]);
        app(HandoffService::class)->push($outsideRow->id);
        $this->assertSame(InspectionAppHandoff::STATUS_SKIPPED, $outsideRow->fresh()->status);

        Http::assertNothingSent();
    }

    public function test_a_crystal_creek_outside_customer_work_order_carries_the_customer_instead_of_a_property(): void
    {
        Http::fake(['inspection-api.example.test/*' => Http::response(['id' => 'insp-cca'], 201)]);
        $customer = OutsideCustomer::factory()->create([
            'name' => 'Pat Customer',
            'phone' => '5125550100',
            'email' => 'pat@example.com',
            'street' => '1234 Oak St',
            'city' => 'Cypress',
            'state' => 'TX',
            'postal_code' => '77429',
        ]);
        $workOrder = $this->workOrderWith([$this->thmp()], [
            'work_order_no' => 7,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'building_id' => null,
            'tenant_id' => null,
        ]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id]);

        app(HandoffService::class)->push($row->id);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $this->assertTrue($body['work_order']['is_crystal_creek']);
            $this->assertNull($body['property']);
            $this->assertSame('Pat Customer', $body['outside_customer']['name']);
            $this->assertSame('+15125550100', $body['outside_customer']['phone']);
            $this->assertSame('1234 Oak St, Cypress, TX 77429', $body['outside_customer']['address']);
            $this->assertSame([], $body['tenants']);

            return true;
        });
    }

    // ---- The sweep ------------------------------------------------------

    public function test_the_sweep_pushes_pending_rows_and_resends_sent_rows_the_work_order_changed_since(): void
    {
        Queue::fake();
        $thmp = $this->thmp();
        $pending = $this->workOrderWith([$thmp]);
        $unchanged = $this->workOrderWith([$thmp]);
        $changed = $this->workOrderWith([$thmp]);
        $refused = $this->workOrderWith([$thmp]);

        InspectionAppHandoff::query()->create(['work_order_id' => $pending->id, 'created_at' => now()->subMinutes(10)]);
        InspectionAppHandoff::query()->create(['work_order_id' => $unchanged->id, 'status' => InspectionAppHandoff::STATUS_SENT, 'sent_at' => now()->addMinute(), 'last_attempt_at' => now()->addMinute()]);
        InspectionAppHandoff::query()->create(['work_order_id' => $changed->id, 'status' => InspectionAppHandoff::STATUS_SENT, 'sent_at' => now()->subHour(), 'last_attempt_at' => now()->subHour()]);
        InspectionAppHandoff::query()->create(['work_order_id' => $refused->id, 'status' => InspectionAppHandoff::STATUS_FAILED, 'last_attempt_at' => now()->addMinute()]);

        $this->artisan('inspection-app:push-work-orders')
            ->expectsOutputToContain('2 queued')
            ->assertExitCode(0);

        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 2);
        $this->assertSame(InspectionAppHandoff::STATUS_PENDING, InspectionAppHandoff::query()->where('work_order_id', $changed->id)->value('status'));
        $this->assertSame(InspectionAppHandoff::STATUS_SENT, InspectionAppHandoff::query()->where('work_order_id', $unchanged->id)->value('status'));
        $this->assertSame(InspectionAppHandoff::STATUS_FAILED, InspectionAppHandoff::query()->where('work_order_id', $refused->id)->value('status'));
    }

    public function test_the_sweep_retries_a_refused_row_once_the_work_order_changed(): void
    {
        Queue::fake();
        $refused = $this->workOrderWith([$this->thmp()]);
        InspectionAppHandoff::query()->create(['work_order_id' => $refused->id, 'status' => InspectionAppHandoff::STATUS_FAILED, 'last_attempt_at' => now()->subHour()]);

        $this->artisan('inspection-app:push-work-orders')->assertExitCode(0);

        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
        $this->assertSame(InspectionAppHandoff::STATUS_PENDING, InspectionAppHandoff::query()->where('work_order_id', $refused->id)->value('status'));
    }

    public function test_the_sweep_dry_run_and_the_off_switch_queue_nothing(): void
    {
        Queue::fake();
        $pending = $this->workOrderWith([$this->thmp()]);
        InspectionAppHandoff::query()->create(['work_order_id' => $pending->id, 'created_at' => now()->subMinutes(10)]);

        $this->artisan('inspection-app:push-work-orders --dry-run')
            ->expectsOutputToContain('1 would be queued')
            ->assertExitCode(0);

        config(['services.inspection_app.handoff' => false]);
        $this->artisan('inspection-app:push-work-orders')
            ->expectsOutputToContain('off')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_the_sweep_can_push_one_work_order_right_now_by_number(): void
    {
        Http::fake(['inspection-api.example.test/*' => Http::response(['id' => 'insp-1'], 201)]);
        $workOrder = $this->workOrderWith([$this->thmp()], ['work_order_no' => 43761]);

        $this->artisan('inspection-app:push-work-orders --work-order=43761')
            ->expectsOutputToContain('sent')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertSame(InspectionAppHandoff::STATUS_SENT, InspectionAppHandoff::query()->where('work_order_id', $workOrder->id)->value('status'));
    }
}
