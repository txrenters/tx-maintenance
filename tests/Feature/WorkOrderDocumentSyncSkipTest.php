<?php

namespace Tests\Feature;

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
}
