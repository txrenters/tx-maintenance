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

        // Deterministic extraction so tests never touch a PDF parser or the AI.
        $this->app->instance(HoaNoticeExtractor::class, new class extends HoaNoticeExtractor
        {
            public function extract(string $pdfContents): array
            {
                return [
                    'description' => 'Trim the front lawn and remove the trailer from the driveway.',
                    'violation_items' => ['Trim the front lawn', 'Remove the trailer'],
                    'notice_date' => null,
                    'hoa_name' => 'Oak Ridge HOA',
                    'source' => 'stub',
                ];
            }
        });
    }

    private function building(): Building
    {
        return Building::query()->create([
            'propertyware_id' => 7001,
            'name' => '123 Oak Ridge Dr',
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

    public function test_upload_creates_a_local_hoa_work_order_with_a_token_and_deadline(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        config(['services.twilio.hoa_violation_sms' => false]);
        Storage::fake('public');
        Queue::fake();
        $this->mockPropertyWare(null);

        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'building_id' => $building->propertyware_id,
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
                'hoa_notice_date' => '2026-07-20',
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

        // The notice PDF is attached to the work order.
        $this->assertDatabaseHas('attachments', [
            'work_order_id' => $workOrder->id,
            'title' => 'HOA violation notice',
        ]);
    }

    public function test_pw_create_path_imports_the_work_order_back(): void
    {
        config(['services.hoa.pw_create_enabled' => true]);
        config(['services.twilio.hoa_violation_sms' => false]);
        Storage::fake('public');
        Queue::fake();

        // PropertyWare create returns an id, and a matching local row exists as
        // if the import ran.
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
                'building_id' => $building->propertyware_id,
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
            ])->assertRedirect();

        $imported->refresh();
        $this->assertSame('HOA Violation', $imported->category);
        $this->assertStringContainsString('Trim the front lawn', $imported->description);

        // No duplicate local-only row was created.
        $this->assertSame(1, WorkOrder::query()->where('category', 'HOA Violation')->count());
    }

    public function test_an_existing_open_hoa_work_order_is_reused_not_duplicated(): void
    {
        config(['services.hoa.pw_create_enabled' => false]);
        config(['services.twilio.hoa_violation_sms' => false]);
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
                'building_id' => $building->propertyware_id,
                'file' => UploadedFile::fake()->create('notice.pdf', 200, 'application/pdf'),
            ])->assertRedirect();

        // Still exactly one HOA work order for this building.
        $this->assertSame(1, WorkOrder::query()->where('category', 'HOA Violation')->count());
        $this->assertDatabaseHas('attachments', ['work_order_id' => $existing->id]);
    }

    public function test_a_pdf_is_required(): void
    {
        $building = $this->building();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('work_orders.hoa.store'), [
                'building_id' => $building->propertyware_id,
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])->assertSessionHasErrors('file');
    }
}
