<?php

namespace Tests\Feature;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
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
        $mock->shouldReceive('getLatestWorkOrderForBuilding')
            ->andReturn(['location' => 'MULLERJJ | 3235QUARRYPL', 'unitIDs' => [4209311746]]);
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

    public function test_a_stated_deadline_already_past_still_gives_the_tenant_two_business_days(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        // The real WO#43864 case: a notice demanding 8/16 (Sun) uploaded 8/18
        // (Tue). Honoring the blown date as-is made the violation born-overdue —
        // staff were flagged to send a vendor the day it was uploaded, before
        // the tenant got a single message.
        $this->travelTo(Carbon::parse('2026-08-18 09:00:00'));

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'notice_date' => '2026-08-11',
                    'deadline_date' => '2026-08-16',
                ])],
            ])->assertRedirect();

        $token = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->firstOrFail();

        // Floored to 8/18 (Tue) + 2 business days => 8/20 (Thu).
        $this->assertSame('2026-08-20', $token->hoa_deadline_at->toDateString());
        $this->assertFalse($token->hoa_deadline_at->isPast());
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
        $mock->shouldReceive('getLatestWorkOrderForBuilding')
            ->andReturn(['location' => 'MULLERJJ | 3235QUARRYPL', 'unitIDs' => [4209311746]]);
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

        // PropertyWare's category picklist accepts "HOA Violation" (verified by
        // a live create on 2026-08-04), so violations are filed under their real
        // name and the board card no longer reads "General Maintenance".
        $this->assertSame('HOA Violation', $captured['category']);
        $this->assertSame('General', $captured['type']);
    }

    public function test_pw_create_sends_the_buildings_propertyware_location_and_unit(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $building = $this->building();

        // PropertyWare validates the create against its "Location" (the unit)
        // and rejects the whole call with "Location is invalid" unless the
        // piped location string and unit ID both match what it has on record —
        // which is what silently turned every HOA intake into a local-only work
        // order. Both are copied off the newest work order PropertyWare itself
        // holds for the building.
        $captured = null;
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getLatestWorkOrderForBuilding')
            ->once()
            ->with($building->propertyware_id)
            ->andReturn(['location' => 'MULLERJJ | 3235QUARRYPL', 'unitIDs' => [4209311746]]);
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

        $this->assertSame('MULLERJJ | 3235QUARRYPL', $captured['location']);
        $this->assertSame(4209311746, $captured['unit_id']);
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
        // send a payload it knows will be rejected ("Location is invalid").
        $this->assertNull(app(PropertyWareService::class)->createWorkOrder([
            'building_id' => 7001,
            'portfolio_id' => 900,
            'category' => 'General Maintenance',
            'description' => 'Remove weeds from the driveway.',
            'type' => 'General',
            'location' => null,
            'unit_id' => null,
        ]));
    }

    /**
     * An HOA violation this feature is already chasing on the property: an
     * open work order carrying the HOA token (HOA identity is the token, not
     * the category), with the notice it came from.
     */
    private function openViolation(Building $building, string $noticeDate, int $number = 43749): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create([
            'building_id' => $building->propertyware_id,
            'work_order_no' => $number,
            'status' => 'Open',
            'description' => "Bring the decorative planters into compliance.\n\nItems to correct:\n- Limit planters to 4 total\n\nNotice issued by: Cinco Ranch",
        ]);

        TenantUploadToken::create([
            'token' => TenantUploadToken::generateUniqueToken(),
            'work_order_id' => $workOrder->id,
            'purpose' => TenantUploadToken::PURPOSE_HOA_VIOLATION,
            'hoa_notice_date' => $noticeDate,
            'hoa_deadline_at' => Carbon::parse($noticeDate)->addWeekdays(5)->endOfDay(),
        ]);

        return $workOrder;
    }

    public function test_a_new_notice_on_a_property_with_an_open_violation_gets_its_own_work_order(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $existing = $this->openViolation($building, '2026-07-30');

        $user = User::factory()->create();

        // No attach_to_work_order_id: staff left the default, "new work order".
        // A later letter about something else must not disappear into the
        // violation already being chased — the 5231 Shadow Breeze courtesy
        // notice of 2026-08-31 did exactly that (WO #43749, 2026-09-02): no
        // PropertyWare work order, and the tenant never told.
        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, ['notice_date' => '2026-08-31'])],
            ])->assertRedirect();

        $this->assertSame(2, WorkOrder::query()->hoaViolations()->count());

        $new = WorkOrder::query()->hoaViolations()->whereKeyNot($existing->id)->firstOrFail();
        $this->assertStringContainsString('Trim the front lawn', $new->description);
        $this->assertDatabaseHas('attachments', ['work_order_id' => $new->id, 'title' => 'HOA violation notice']);
        $this->assertDatabaseMissing('attachments', ['work_order_id' => $existing->id]);

        // The new violation runs its own tenant workflow.
        $token = TenantUploadToken::query()->where('work_order_id', $new->id)->firstOrFail();
        $this->assertSame('2026-08-31', $token->hoa_notice_date->toDateString());

        // And the old one is untouched.
        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $existing->id)->count());
        $this->assertStringContainsString('planters', $existing->fresh()->description);

        // (PropertyWare creation is off in this test, so the message goes on
        // to warn about the local-only work order — that part is covered by
        // its own test above.)
        $this->assertStringStartsWith('1 HOA violation work order created.', (string) session('success'));
    }

    public function test_a_notice_staff_marked_as_a_follow_up_attaches_to_the_open_violation(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $existing = $this->openViolation($building, '2026-07-30');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'attach_to_work_order_id' => $existing->id,
                ])],
            ])->assertRedirect();

        $this->assertSame(1, WorkOrder::query()->hoaViolations()->count());
        $this->assertDatabaseHas('attachments', ['work_order_id' => $existing->id, 'title' => 'HOA violation notice']);

        // One reminder cadence per violation: the open token is kept, and the
        // description stays the violation's own.
        $this->assertSame(1, TenantUploadToken::query()->where('work_order_id', $existing->id)->count());
        $this->assertStringContainsString('planters', $existing->fresh()->description);

        // Staff must never read "created" for a notice that made no work order.
        $this->assertSame('1 notice attached to existing work order #43749.', session('success'));
    }

    public function test_attaching_to_a_work_order_that_is_no_longer_the_open_violation_fails_that_notice(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $existing = $this->openViolation($building, '2026-07-30');

        // Closed between the scan and the submit (or the id belongs to some
        // other work order): nothing is created and nothing is attached, and
        // the reason reaches the screen instead of a silent redirect.
        $existing->update(['status' => 'Closed']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id, [
                    'attach_to_work_order_id' => $existing->id,
                ])],
            ])->assertSessionHasErrors('error');

        $this->assertStringContainsString('no longer the open HOA violation', session('errors')->first('error'));
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->hoaViolations()->count());
        $this->assertDatabaseMissing('attachments', ['work_order_id' => $existing->id]);
    }

    public function test_detect_reports_the_violation_already_open_on_the_matched_property(): void
    {
        Storage::fake('public');
        $building = $this->building(7001, '10107 Mariposa Green Ct');
        $existing = $this->openViolation($building, '2026-07-30');

        $this->stubExtractor([
            [
                'page' => 1,
                'property_address' => '10107 Mariposa Green Ct',
                'description' => 'Remove dead branches from the landscaping.',
                'violation_items' => ['Dead branches in the front beds'],
                'hoa_name' => 'Cinco Ranch',
                'notice_date' => Carbon::parse('2026-08-31'),
                'deadline_date' => null,
                'deadline_days' => 30,
                'source' => 'stub',
            ],
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('work_orders.hoa.detect'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('notices.0.existing_work_order.id', $existing->id)
            ->assertJsonPath('notices.0.existing_work_order.work_order_no', 43749)
            ->assertJsonPath('notices.0.existing_work_order.notice_date', '2026-07-30')
            ->assertJsonPath('notices.0.existing_work_order.summary', 'Limit planters to 4 total');

        // The lookup staff hit when they change the property says the same…
        $this->actingAs($user)
            ->getJson(route('work_orders.hoa.contacts', ['building_id' => $building->propertyware_id]))
            ->assertOk()
            ->assertJsonPath('existing_work_order.id', $existing->id);

        // …and a property with nothing open offers nothing to attach to.
        $this->building(7002, '200 Elm St');

        $this->actingAs($user)
            ->getJson(route('work_orders.hoa.contacts', ['building_id' => 7002]))
            ->assertOk()
            ->assertJsonPath('existing_work_order', null);
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

    public function test_the_notice_upload_to_propertyware_is_queued_and_the_name_waits_for_confirmation(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $this->mockPropertyWare('88999');
        WorkOrder::factory()->create([
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

        $attachment = Attachments::withoutGlobalScopes()
            ->where('title', 'HOA violation notice')
            ->firstOrFail();

        // The push now runs on the queue with retries, replacing the old inline
        // call whose failures were swallowed while intake reported success.
        Queue::assertPushed(UploadHoaNoticeToPropertyWare::class, function (UploadHoaNoticeToPropertyWare $job) use ($attachment) {
            return $job->attachmentId === $attachment->id
                && str_starts_with($job->pwFileName, 'HOA Notice - WO55123 - ');
        });

        // Null until PropertyWare confirms — the document sync skips names it
        // finds here, so an eager write would hide a failed upload forever.
        $this->assertNull($attachment->pw_file_name);
    }

    public function test_no_upload_is_queued_for_a_local_only_work_order(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        // Nothing to push to: the work order only exists locally.
        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
    }

    public function test_a_photo_notice_is_queued_to_propertyware_under_its_own_extension(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        Storage::fake('public');
        Queue::fake();

        $this->mockPropertyWare('88999');
        WorkOrder::factory()->create([
            'propertyware_id' => '88999',
            'work_order_no' => 55123,
            'category' => 'Maintenance',
        ]);

        $building = $this->building();
        $user = User::factory()->create();

        // A photographed notice is the same document as a scanned one and
        // belongs in PropertyWare's DOCS just the same. Gating the push on
        // application/pdf is what left WO#43822's notice on the dashboard only,
        // with no log line and no failed job to find it by.
        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->image('notice.jpg'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $attachment = Attachments::withoutGlobalScopes()
            ->where('title', 'HOA violation notice')
            ->firstOrFail();

        // PropertyWare types the document off the name, so the photo must not
        // arrive called .pdf.
        Queue::assertPushed(UploadHoaNoticeToPropertyWare::class, function (UploadHoaNoticeToPropertyWare $job) use ($attachment) {
            return $job->attachmentId === $attachment->id
                && str_starts_with($job->pwFileName, 'HOA Notice - WO55123 - ')
                && str_ends_with($job->pwFileName, '.jpg');
        });

        $this->assertNull($attachment->pw_file_name);
    }
}
