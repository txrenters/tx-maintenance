<?php

namespace Tests\Feature;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class VendorAssignmentEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_persists_outbound_email_with_tag_via_graph(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID', 'internetMessageId' => '<x@texasrenters.com>', 'conversationId' => 'CID',
            ]),
        ]);

        // PDF + PropertyWare are external; stub them so the job's email path runs in isolation.
        $pdf = Mockery::mock(WorkOrderInformationPdf::class);
        $pdf->shouldReceive('render')->andReturn('%PDF-fake');
        $this->app->instance(WorkOrderInformationPdf::class, $pdf);

        $pw = Mockery::mock(PropertyWareService::class);
        $pw->shouldReceive('uploadWorkOrderPdf')->andReturn(false);
        $this->app->instance(PropertyWareService::class, $pw);

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'ABC Plumbing', 'email' => 'abc@example.com', 'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TX-4321-'.$vendor->id,
        ]);
    }
}
