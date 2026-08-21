<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderDocumentDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->create();
    }

    private function makeDocument(WorkOrder $workOrder, string $fileName, array $overrides = []): WorkOrderDocuments
    {
        return WorkOrderDocuments::create(array_merge([
            'work_order_id' => $workOrder->id,
            'propertyware_id' => fake()->unique()->numberBetween(1, 1_000_000_000),
            'file_name' => $fileName,
        ], $overrides));
    }

    private function makeAttachment(WorkOrder $workOrder, array $overrides = []): Attachments
    {
        $user = User::factory()->create();

        return Attachments::create(array_merge([
            'title' => 'Photo',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => $user->id,
        ], $overrides));
    }

    public function test_is_duplicate_when_document_with_same_name_already_exists(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeDocument($workOrder, 'Work Order Information.pdf');

        $this->assertTrue(
            WorkOrderDocuments::isDuplicateForWorkOrder($workOrder->id, 'Work Order Information.pdf')
        );
    }

    public function test_is_duplicate_when_matching_an_uploaded_attachment_name(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeAttachment($workOrder, ['pw_file_name' => 'Leak_photo_20260623_174621.jpg']);

        $this->assertTrue(
            WorkOrderDocuments::isDuplicateForWorkOrder($workOrder->id, 'Leak_photo_20260623_174621.jpg')
        );
    }

    public function test_is_not_duplicate_for_a_new_file_name(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->assertFalse(
            WorkOrderDocuments::isDuplicateForWorkOrder($workOrder->id, 'Brand_new_file.pdf')
        );
    }

    public function test_is_not_duplicate_when_same_name_belongs_to_another_work_order(): void
    {
        $workOrderA = $this->makeWorkOrder();
        $workOrderB = $this->makeWorkOrder();
        $this->makeDocument($workOrderA, 'Work Order Information.pdf');

        $this->assertFalse(
            WorkOrderDocuments::isDuplicateForWorkOrder($workOrderB->id, 'Work Order Information.pdf')
        );
    }

    public function test_dedupe_command_collapses_repeated_file_names_keeping_the_oldest(): void
    {
        $workOrder = $this->makeWorkOrder();
        $keep = $this->makeDocument($workOrder, 'Work Order Information.pdf');
        $dropA = $this->makeDocument($workOrder, 'Work Order Information.pdf');
        $dropB = $this->makeDocument($workOrder, 'Work Order Information.pdf');
        $unrelated = $this->makeDocument($workOrder, 'Invoice.pdf');

        $this->artisan('work-order-documents:dedupe')->assertSuccessful();

        $this->assertDatabaseHas('work_order_documents', ['id' => $keep->id]);
        $this->assertDatabaseMissing('work_order_documents', ['id' => $dropA->id]);
        $this->assertDatabaseMissing('work_order_documents', ['id' => $dropB->id]);
        $this->assertDatabaseHas('work_order_documents', ['id' => $unrelated->id]);
    }

    public function test_dedupe_command_prefers_keeping_the_app_written_copy(): void
    {
        config(['services.propertyware.username' => 'api-user@texasrenters.com']);

        $workOrder = $this->makeWorkOrder();
        // The stale PropertyWare import came first (lower id); the app wrote
        // its own downloadable copy later. The app's copy must survive.
        $staleImport = $this->makeDocument($workOrder, 'Work Order Information.pdf');
        $appWritten = $this->makeDocument($workOrder, 'Work Order Information.pdf', [
            'created_by_id' => 'api-user@texasrenters.com',
        ]);

        $this->artisan('work-order-documents:dedupe')->assertSuccessful();

        $this->assertDatabaseHas('work_order_documents', ['id' => $appWritten->id]);
        $this->assertDatabaseMissing('work_order_documents', ['id' => $staleImport->id]);
    }

    public function test_thumbnail_file_names_are_detected(): void
    {
        $this->assertTrue(WorkOrderDocuments::isThumbnailFileName('THMP_Invoice INV-4803_WO#43114.pdf'));
        $this->assertFalse(WorkOrderDocuments::isThumbnailFileName('Invoice INV-4803_WO#43114.pdf'));
        $this->assertFalse(WorkOrderDocuments::isThumbnailFileName('Work Order Information.pdf'));
        $this->assertFalse(WorkOrderDocuments::isThumbnailFileName(null));
    }

    public function test_dedupe_command_removes_thumbnail_documents(): void
    {
        $workOrder = $this->makeWorkOrder();
        $thumbnail = $this->makeDocument($workOrder, 'THMP_Invoice INV-4803_WO#43114.pdf');
        $genuine = $this->makeDocument($workOrder, 'Invoice INV-4803_WO#43114.pdf');

        $this->artisan('work-order-documents:dedupe')->assertSuccessful();

        $this->assertDatabaseMissing('work_order_documents', ['id' => $thumbnail->id]);
        $this->assertDatabaseHas('work_order_documents', ['id' => $genuine->id]);
    }

    public function test_dedupe_command_removes_documents_matching_uploaded_attachments(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeAttachment($workOrder, ['pw_file_name' => 'Leak_photo_20260623_174621.jpg']);
        $roundTrip = $this->makeDocument($workOrder, 'Leak_photo_20260623_174621.jpg', ['file_type' => 'image/jpg']);
        $genuine = $this->makeDocument($workOrder, 'Work Order Information.pdf');

        $this->artisan('work-order-documents:dedupe')->assertSuccessful();

        $this->assertDatabaseMissing('work_order_documents', ['id' => $roundTrip->id]);
        $this->assertDatabaseHas('work_order_documents', ['id' => $genuine->id]);
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $workOrder = $this->makeWorkOrder();
        $keep = $this->makeDocument($workOrder, 'Work Order Information.pdf');
        $drop = $this->makeDocument($workOrder, 'Work Order Information.pdf');

        $this->artisan('work-order-documents:dedupe --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('work_order_documents', ['id' => $keep->id]);
        $this->assertDatabaseHas('work_order_documents', ['id' => $drop->id]);
    }
}
