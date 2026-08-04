<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HoaNoticeExtractor;
use App\Services\HoaViolationIntakeService;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class HoaViolationIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->firstOrCreate(
            ['name' => 'Checking for Tenant Easy Fix'],
            ['description' => 'The service request is checking for an easy fix.'],
        );

        config(['services.twilio.hoa_violation_sms' => false]);
    }

    private function building(int $id = 7001, string $name = '123 Oak Ridge Dr'): Building
    {
        return Building::query()->create([
            'propertyware_id' => $id,
            'name' => $name,
            'address' => $name,
            'portfolio_id' => 900,
        ]);
    }

    private function mockPropertyWare(?string $createdId): void
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('createWorkOrder')->andReturn($createdId);
        $mock->shouldReceive('getWorkOrder')->andReturn(['number' => 55123]);
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([]);
        $mock->shouldReceive('uploadWorkOrderPdf')->andReturn('doc-1');
        $mock->shouldReceive('updateServiceStatus')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    /**
     * Stub the extractor so `detect` never touches a PDF parser or the AI.
     *
     * @param  array<int, array<string, mixed>>  $notices
     */
    private function stubExtractor(array $notices): void
    {
        $this->app->instance(HoaNoticeExtractor::class, new class($notices) extends HoaNoticeExtractor
        {
            public function __construct(private array $stubNotices) {}

            public function extractNotices(string $contents, string $mime = 'application/pdf'): array
            {
                return $this->stubNotices;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function notice(int $buildingId, array $overrides = []): array
    {
        return array_merge([
            'building_id' => $buildingId,
            'pages' => [1],
            'description' => 'Trim the front lawn and remove the trailer from the driveway.',
            'violation_items' => ['Trim the front lawn', 'Remove the trailer'],
            'hoa_name' => 'Oak Ridge HOA',
            'notice_date' => '2026-07-20',
        ], $overrides);
    }

    public function test_store_creates_a_local_hoa_work_order_with_a_token_and_deadline(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        // Pinned: the window is counted from today, so a floating "now" would
        // move the expected deadline every day this suite runs.
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $workOrder = WorkOrder::query()->hoaViolations()->firstOrFail();
        $this->assertStringContainsString('Trim the front lawn', $workOrder->description);
        $this->assertEquals(
            ServiceStatus::query()->where('name', 'Checking for Tenant Easy Fix')->value('id'),
            $workOrder->service_status_id,
        );

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        // 2026-07-20 (Mon) + 5 business days => 2026-07-27 (Mon).
        $this->assertSame('2026-07-27', $token->hoa_deadline_at->toDateString());
        $this->assertSame('2026-07-20', $token->hoa_notice_date->toDateString());

        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'title' => 'HOA violation notice',
        ]);
    }

    public function test_a_notice_deadline_further_out_than_the_window_leaves_the_window_alone(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $this->travelTo(Carbon::parse('2026-07-07 09:00:00'));

        $building = $this->building();
        $user = User::factory()->create();

        // Notice says "resolve by 7/28/2026" — plenty of room — so the tenant
        // still runs the standard 5-business-day window.
        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'notice_date' => '2026-07-07',
                    'deadline_date' => '2026-07-28',
                ])],
            ])->assertRedirect();

        $token = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        // 2026-07-07 (Tue) + 5 business days => 2026-07-14 (Tue), well inside
        // the 7/28 the notice asked for.
        $this->assertSame('2026-07-14', $token->hoa_deadline_at->toDateString());
    }

    public function test_a_stale_notice_is_not_overdue_the_moment_it_is_uploaded(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        // The real 2026-08-04 case: a 7/22 notice uploaded on 8/4. Counting the
        // window from the notice date made the work order overdue on arrival and
        // flagged staff to send a vendor before the tenant had been given a day.
        $this->travelTo(Carbon::parse('2026-08-04 09:00:00'));

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'notice_date' => '2026-07-22',
                    'deadline_date' => '2026-08-12',
                ])],
            ])->assertRedirect();

        $token = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        // Counted from today (8/4 Tue) + 5 business days => 8/11 (Tue), landing
        // a day before the 8/12 the association actually set.
        $this->assertSame('2026-08-11', $token->hoa_deadline_at->toDateString());
        $this->assertFalse($token->hoa_deadline_at->isPast());

        // The notice date is still recorded as read from the letter.
        $this->assertSame('2026-07-22', $token->hoa_notice_date->toDateString());
    }

    public function test_a_notice_deadline_sooner_than_the_window_caps_it(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $this->travelTo(Carbon::parse('2026-08-04 09:00:00'));

        $building = $this->building();
        $user = User::factory()->create();

        // An association demanding it fixed by Thursday must not be escalated
        // the following Tuesday, by which point the deadline has already blown.
        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'notice_date' => '2026-08-04',
                    'deadline_date' => '2026-08-06',
                ])],
            ])->assertRedirect();

        $token = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        $this->assertSame('2026-08-06', $token->hoa_deadline_at->toDateString());
    }

    public function test_store_creates_one_work_order_per_notice(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $first = $this->building(7001, '10107 Mariposa Green Ct');
        $second = $this->building(7002, '2803 Briar Breeze Dr');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notices.pdf', 300, 'application/pdf'),
                'notices' => [
                    $this->notice($first->propertyware_id, [
                        'pages' => [1],
                        'description' => 'Store the trash bins out of view on non-trash days.',
                    ]),
                    $this->notice($second->propertyware_id, [
                        'pages' => [2],
                        'description' => 'Remove and replace the dead tree in the front yard.',
                    ]),
                ],
            ])->assertRedirect();

        $this->assertSame(2, WorkOrder::query()->hoaViolations()->count());
        $this->assertSame(1, WorkOrder::query()->where('building_id', $first->propertyware_id)->count());
        $this->assertSame(1, WorkOrder::query()->where('building_id', $second->propertyware_id)->count());
    }

    public function test_a_work_order_categorized_in_propertyware_starts_the_hoa_workflow(): void
    {
        Queue::fake();
        $this->mockPropertyWare(null);

        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        // Raised straight in PropertyWare under the HOA category — no notice
        // was ever uploaded here, so it has no token yet.
        $workOrder = WorkOrder::factory()->create([
            'category' => WorkOrder::HOA_VIOLATION_CATEGORY,
            'status' => 'Open',
            'description' => 'Grill(s) need to be stored out of public view.',
            'created_date' => '2026-07-20',
        ]);

        $adopted = app(HoaViolationIntakeService::class)->adoptCategorizedWorkOrder($workOrder);

        $this->assertTrue($adopted);
        $this->assertTrue(WorkOrder::query()->hoaViolations()->whereKey($workOrder->id)->exists());

        $workOrder->refresh();
        $this->assertEquals(
            ServiceStatus::query()->where('name', 'Checking for Tenant Easy Fix')->value('id'),
            $workOrder->service_status_id,
        );

        // The PropertyWare description is left untouched — there is no notice
        // to read — and the deadline counts from the work order's created date.
        $this->assertSame('Grill(s) need to be stored out of public view.', $workOrder->description);

        $token = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        // 2026-07-20 (Mon) + 5 business days => 2026-07-27 (Mon).
        $this->assertSame('2026-07-27', $token->hoa_deadline_at->toDateString());
    }

    public function test_adoption_is_idempotent_and_ignores_other_categories(): void
    {
        Queue::fake();
        $this->mockPropertyWare(null);

        $service = app(HoaViolationIntakeService::class);

        $hoa = WorkOrder::factory()->create([
            'category' => WorkOrder::HOA_VIOLATION_CATEGORY,
            'status' => 'Open',
        ]);

        $this->assertTrue($service->adoptCategorizedWorkOrder($hoa));
        // A second pass (re-import, retry) must not open a second token or
        // re-text the tenant.
        $this->assertFalse($service->adoptCategorizedWorkOrder($hoa->refresh()));
        $this->assertSame(1, TenantUploadToken::query()
            ->where('work_order_id', $hoa->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->count());

        $plumbing = WorkOrder::factory()->create(['category' => 'Plumbing', 'status' => 'Open']);

        $this->assertFalse($service->adoptCategorizedWorkOrder($plumbing));
        $this->assertSame(0, TenantUploadToken::query()->where('work_order_id', $plumbing->id)->count());
    }

    public function test_a_hand_categorized_work_order_is_not_adopted_as_the_follow_up_target(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        // Staff categorized this one by hand, so it shows on the HOA board but
        // carries no token — a new notice must still get its own work order
        // rather than attaching to a possibly unrelated request.
        $manual = WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'category' => WorkOrder::HOA_VIOLATION_CATEGORY,
            'status' => 'Open',
        ]);

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        // A second work order was raised rather than the notice attaching to
        // the hand-categorized one, and only the new one is tracked.
        $this->assertSame(2, WorkOrder::query()->where('building_id', $building->propertyware_id)->count());
        $this->assertSame(1, WorkOrder::query()->hoaViolations()->count());
        $this->assertFalse(
            $manual->tenantUploadTokens()
                ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
                ->exists(),
        );
    }

    public function test_pw_create_path_imports_the_work_order_back(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $this->mockPropertyWare('88999');
        $imported = WorkOrder::factory()->create([
            'propertyware_id' => '88999',
            'work_order_no' => 55123,
            'category' => 'Maintenance',
        ]);

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $imported->refresh();
        // The PW-sent category is left as-is (identity is the HOA token, not the
        // category); the description is refreshed from the extracted notice.
        $this->assertStringContainsString('Trim the front lawn', $imported->description);
        $this->assertTrue(
            $imported->tenantUploadTokens()
                ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
                ->exists(),
        );

        $this->assertSame(1, WorkOrder::query()->hoaViolations()->count());
    }

    public function test_pw_create_sends_a_valid_type_and_category(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $captured = null;
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->andReturnUsing(function (array $data) use (&$captured) {
                $captured = $data;

                return null; // fall back to local-only; we only assert the payload
            });
        $mock->shouldReceive('getWorkOrder')->andReturn(['number' => 55123]);
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([]);
        $mock->shouldReceive('uploadWorkOrderPdf')->andReturn('doc-1');
        $mock->shouldReceive('updateServiceStatus')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $mock);

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        // PropertyWare rejects unknown picklist values, so the create must use an
        // existing valid type/category — never the 'HOA Violation' label.
        $this->assertSame('General Maintenance', $captured['category']);
        $this->assertSame('General', $captured['type']);
        $this->assertNotSame('HOA Violation', $captured['category']);
    }

    public function test_pw_create_sends_the_buildings_propertyware_location(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $building = $this->building();

        // PropertyWare validates the location against the building and fails the
        // whole create with "Location is invalid" when it is missing — which is
        // what silently turned every HOA intake into a local-only work order.
        // Its own string for a building is already on every work order imported
        // for it, so the create reuses the most recent one.
        WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'propertyware_id' => 6244040781,
            'location' => 'MULLERJJ 3235QUARRYPL',
        ]);

        $captured = null;
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('createWorkOrder')
            ->once()
            ->andReturnUsing(function (array $data) use (&$captured) {
                $captured = $data;

                return null;
            });
        $mock->shouldReceive('getWorkOrder')->andReturn(['number' => 55123]);
        $mock->shouldReceive('getWorkOrderByNumber')->andReturn([]);
        $mock->shouldReceive('uploadWorkOrderPdf')->andReturn('doc-1');
        $mock->shouldReceive('updateServiceStatus')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $mock);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $this->assertSame('MULLERJJ 3235QUARRYPL', $captured['location']);
    }

    public function test_a_work_order_propertyware_refused_is_reported_to_staff(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        // PropertyWare rejected it: the work order exists locally only, has no
        // number, and will never sync. Reporting a plain success for that is how
        // this went unnoticed from the feature shipping until 2026-08-04.
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ]);

        $response->assertRedirect();
        $this->assertStringContainsString(
            'could not be created in PropertyWare',
            (string) session('success'),
        );

        // And the orphan is labelled for what it is, rather than hiding on the
        // board as a generic maintenance job.
        $workOrder = WorkOrder::query()->hoaViolations()->firstOrFail();
        $this->assertNull($workOrder->work_order_no);
        $this->assertSame(WorkOrder::HOA_VIOLATION_CATEGORY, $workOrder->category);
    }

    public function test_pw_create_is_refused_outright_when_no_location_can_be_resolved(): void
    {
        // Rather than let PropertyWare fail the create, the service declines to
        // send a payload it knows will be rejected.
        $this->assertNull(app(PropertyWareService::class)->createWorkOrder([
            'building_id' => 7001,
            'portfolio_id' => 900,
            'category' => 'General Maintenance',
            'description' => 'Remove weeds from the driveway.',
            'type' => 'General',
            'location' => null,
        ]));
    }

    public function test_an_existing_open_hoa_work_order_is_reused_not_duplicated(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();

        $existing = WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'status' => 'Open',
        ]);
        // HOA identity is the token, so the existing open HOA work order must
        // carry one for the dedup to recognise it.
        TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $existing->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $this->assertSame(1, WorkOrder::query()->hoaViolations()->count());
        $this->assertDatabaseHas('attachments', ['work_order_id' => $existing->id]);
    }

    public function test_detect_reads_notices_and_matches_the_property(): void
    {
        Storage::fake('public');
        $building = $this->building(7001, '10107 Mariposa Green Ct');

        $this->stubExtractor([
            [
                'page' => 1,
                'property_address' => '10107 Mariposa Green Ct',
                'description' => 'Store the trash bins out of view on non-trash days.',
                'violation_items' => ['Trash bins visible from the street'],
                'hoa_name' => 'Hidden Meadow',
                'notice_date' => null,
                'deadline_date' => null,
                'deadline_days' => 10,
                'source' => 'stub',
            ],
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('work_orders.hoa.detect'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
            ]);

        $response->assertOk()
            ->assertJsonPath('notices.0.property_address', '10107 Mariposa Green Ct')
            ->assertJsonPath('notices.0.matched.id', $building->propertyware_id);
    }

    public function test_a_photo_upload_is_accepted(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        // A phone photo of the notice is a valid upload, not just a PDF.
        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->image('notice.jpg'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $this->assertSame(1, WorkOrder::query()->hoaViolations()->count());
        $this->assertDatabaseHas('attachments', [
            'work_order_id' => WorkOrder::query()->hoaViolations()->value('id'),
            'filetype' => 'image/jpeg',
        ]);
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertSessionHasErrors('file');
    }
}
