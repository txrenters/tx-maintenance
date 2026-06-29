<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkOrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachments_show_includes_work_order_documents(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $document = WorkOrderDocuments::create([
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 8609103940,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);

        $response = $this->actingAs($user)->getJson(route('api.attachments.show', $workOrder));

        $response->assertOk();
        $response->assertJsonPath('documents.0.id', $document->id);
        $response->assertJsonPath('documents.0.file_name', 'Work Order Information.pdf');
    }

    public function test_document_download_streams_content_from_propertyware(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $document = WorkOrderDocuments::create([
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 8609103940,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);

        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')
                ->once()
                ->with(8609103940)
                ->andReturn(['content' => '%PDF-1.4 fake bytes', 'mime' => 'application/pdf']);
        });

        $response = $this->actingAs($user)->get(route('api.work_order_documents.download', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Work Order Information.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake bytes', $response->getContent());
    }

    public function test_document_download_returns_404_when_unavailable(): void
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->create();

        $document = WorkOrderDocuments::create([
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 8609103940,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);

        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('downloadDocument')->once()->andReturn(null);
        });

        $this->actingAs($user)
            ->get(route('api.work_order_documents.download', $document))
            ->assertNotFound();
    }
}
