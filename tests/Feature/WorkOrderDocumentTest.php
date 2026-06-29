<?php

namespace Tests\Feature;

use App\Models\Scopes\WorkOrderScope;
use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // WorkOrderScope caches the resolved user in a static property; reset it so
        // it cannot leak between tests in the same process.
        $cached = new ReflectionProperty(WorkOrderScope::class, 'cachedUser');
        $cached->setAccessible(true);
        $cached->setValue(null, null);
    }

    private function document(WorkOrder $workOrder): WorkOrderDocuments
    {
        return WorkOrderDocuments::create([
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 8609103940,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);
    }

    public function test_attachments_show_includes_work_order_documents(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();
        $document = $this->document($workOrder);

        $response = $this->actingAs($user)->getJson(route('api.attachments.show', $workOrder));

        $response->assertOk();
        $response->assertJsonPath('documents.0.id', $document->id);
        $response->assertJsonPath('documents.0.file_name', 'Work Order Information.pdf');
    }

    public function test_document_download_streams_content_inline_for_safe_mime(): void
    {
        $user = User::factory()->create();
        $document = $this->document(WorkOrder::factory()->create());

        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')
                ->once()
                ->with(8609103940)
                ->andReturn(['content' => '%PDF-1.4 fake bytes', 'mime' => 'application/pdf']);
        });

        $response = $this->actingAs($user)->get(route('api.work_order_documents.download', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake bytes', $response->getContent());
    }

    public function test_document_download_forces_unsafe_mime_to_download(): void
    {
        $user = User::factory()->create();
        $document = $this->document(WorkOrder::factory()->create());

        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')
                ->once()
                ->andReturn(['content' => '<script>alert(1)</script>', 'mime' => 'text/html']);
        });

        $response = $this->actingAs($user)->get(route('api.work_order_documents.download', $document));

        $response->assertOk();
        // Untrusted text/html must not be served inline as text/html.
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_document_download_returns_404_when_unavailable(): void
    {
        $user = User::factory()->create();
        $document = $this->document(WorkOrder::factory()->create());

        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')->once()->andReturn(null);
        });

        $this->actingAs($user)
            ->get(route('api.work_order_documents.download', $document))
            ->assertNotFound();
    }

    public function test_document_download_is_forbidden_for_users_without_work_order_access(): void
    {
        Role::findOrCreate('tenant', 'web');
        $tenantUser = User::factory()->create();
        $tenantUser->assignRole('tenant');
        Tenants::factory()->create([
            'user_id' => $tenantUser->id,
            'first_name' => 'Tess',
            'last_name' => 'Tenant',
        ]);

        // Work order belongs to no tenant, so the tenant-scoped user cannot see it.
        $workOrder = WorkOrder::factory()->create(['tenant_id' => null]);
        $document = $this->document($workOrder);

        // Authorization must fail before PropertyWare is ever contacted.
        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')->never();
        });

        $this->actingAs($tenantUser)
            ->get(route('api.work_order_documents.download', $document))
            ->assertNotFound();
    }
}
