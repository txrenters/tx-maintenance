<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class WorkOrderDocumentSyncSkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_created_by_the_system_user_are_not_imported(): void
    {
        Mail::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '12345']);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrderDocuments')
            ->with('12345')
            ->andReturn([
                [
                    'id' => 'doc-system',
                    'createdBy' => 'a0e71e98',
                    'fileName' => 'System Generated.pdf',
                    'fileType' => 'application/pdf',
                ],
                [
                    'id' => 'doc-real',
                    'createdBy' => 'mrodrigu',
                    'fileName' => 'Invoice.pdf',
                    'fileType' => 'application/pdf',
                ],
            ]);

        $this->app->instance(PropertyWareService::class, $mock);

        $this->artisan('import:work-order-documents')->assertExitCode(0);

        $this->assertDatabaseMissing('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 'doc-system',
        ]);
        $this->assertDatabaseHas('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 'doc-real',
        ]);
    }

    public function test_an_unconfirmed_hoa_notice_no_longer_masks_a_late_arriving_document(): void
    {
        Mail::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '12345']);

        // The HOA push failed, so pw_file_name stayed null — a doc under that
        // name arriving from someone else (e.g. staff re-attaching it in
        // PropertyWare by hand) must still import.
        Attachments::withoutGlobalScopes()->create([
            'title' => 'HOA violation notice',
            'filename' => 'attachments/notice.pdf',
            'filetype' => 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'pw_file_name' => null,
        ]);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrderDocuments')
            ->with('12345')
            ->andReturn([
                [
                    'id' => 'doc-hoa',
                    'createdBy' => 'mrodrigu',
                    'fileName' => 'HOA Notice - WO55123 - 2026-08-11.pdf',
                    'fileType' => 'application/pdf',
                ],
            ]);
        $this->app->instance(PropertyWareService::class, $mock);

        $this->artisan('import:work-order-documents')->assertExitCode(0);

        $this->assertDatabaseHas('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 'doc-hoa',
        ]);
    }

    public function test_a_confirmed_hoa_notice_upload_is_still_skipped_by_name(): void
    {
        Mail::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '12345']);

        // Upload confirmed: the job wrote the exact PropertyWare name back, so
        // the sync must not re-import our own upload as a duplicate document.
        Attachments::withoutGlobalScopes()->create([
            'title' => 'HOA violation notice',
            'filename' => 'attachments/notice.pdf',
            'filetype' => 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'pw_file_name' => 'HOA Notice - WO55123 - 2026-08-11.pdf',
        ]);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrderDocuments')
            ->with('12345')
            ->andReturn([
                [
                    'id' => 'doc-hoa',
                    'createdBy' => 'mrodrigu',
                    'fileName' => 'HOA Notice - WO55123 - 2026-08-11.pdf',
                    'fileType' => 'application/pdf',
                ],
            ]);
        $this->app->instance(PropertyWareService::class, $mock);

        $this->artisan('import:work-order-documents')->assertExitCode(0);

        $this->assertDatabaseMissing('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 'doc-hoa',
        ]);
    }
}
