<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HoaNoticeExtractor;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $workOrder = WorkOrder::query()->where('category', 'HOA Violation')->firstOrFail();
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

    public function test_the_notice_deadline_is_honored_when_stated(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        // Notice says "resolve by 7/28/2026" — that beats the default window.
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

        $this->assertSame('2026-07-28', $token->hoa_deadline_at->toDateString());
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

        $this->assertSame(2, WorkOrder::query()->where('category', 'HOA Violation')->count());
        $this->assertSame(1, WorkOrder::query()->where('building_id', $first->propertyware_id)->count());
        $this->assertSame(1, WorkOrder::query()->where('building_id', $second->propertyware_id)->count());
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
        $this->assertSame('HOA Violation', $imported->category);
        $this->assertStringContainsString('Trim the front lawn', $imported->description);

        $this->assertSame(1, WorkOrder::query()->where('category', 'HOA Violation')->count());
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
            'category' => 'HOA Violation',
            'status' => 'Open',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'notices' => [$this->notice($building->propertyware_id)],
            ])->assertRedirect();

        $this->assertSame(1, WorkOrder::query()->where('category', 'HOA Violation')->count());
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

        $this->assertSame(1, WorkOrder::query()->where('category', 'HOA Violation')->count());
        $this->assertDatabaseHas('attachments', [
            'work_order_id' => WorkOrder::query()->where('category', 'HOA Violation')->value('id'),
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
