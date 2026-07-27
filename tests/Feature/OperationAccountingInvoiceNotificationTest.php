<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationAccountingInvoiceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::query()->create([
            'propertyware_id' => 'V-1',
            'name' => 'Acme Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    private function makeWorkOrder(string $type): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'New',
            'description' => 'New',
        ]);

        $building = Building::query()->create([
            'propertyware_id' => 'B-1001',
            'name' => 'Maple Court',
            'address' => '2927 Burning Tree Ln',
            'city' => 'Houston',
            'state_region' => 'TX',
            'postal_code' => '77339',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
            'description' => 'Full make-ready',
            'type' => $type,
            'building_id' => $building->propertyware_id,
        ]);
    }

    private function mockGraphExpectingOaEmail(WorkOrder $workOrder): void
    {
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('sendMail')
            ->once()
            ->withArgs(function ($to, $cc, $subject, $html, $attachments, $mailbox = null, $replyTo = []) use ($workOrder) {
                return $to === 'oa@texasrenters.com'
                    && $cc === []
                    && str_starts_with($subject, '2927 Burning Tree Ln - ')
                    && str_contains($subject, 'Work Order #4567')
                    && str_contains($html, 'Maple Court')
                    && str_contains($html, '2927 Burning Tree Ln')
                    && str_contains($html, 'Acme Plumbing')
                    && str_contains($html, '$325.00')
                    && str_contains($html, route('work_orders.details', $workOrder))
                    && str_contains($html, 'please do not reply')
                    && $replyTo === ['no-reply@texasrenters.com']
                    && count($attachments) === 1
                    && str_ends_with($attachments[0]['name'], '.pdf')
                    && $attachments[0]['contentBytes'] === base64_encode('%PDF-1.4 fake invoice');
            })
            ->andReturn([
                'graph_message_id' => 'OA-G1',
                'internet_message_id' => '<oa-g1>',
                'graph_conversation_id' => 'OA-C1',
            ]);
        $this->app->instance(MicrosoftGraphMailService::class, $graph);
    }

    private function mockGraphExpectingNoEmail(): void
    {
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldNotReceive('sendMail');
        $this->app->instance(MicrosoftGraphMailService::class, $graph);
    }

    public function test_turnover_invoice_from_vendor_portal_emails_operation_accounting(): void
    {
        Storage::fake('public');
        Http::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder('Turnover');
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->mockGraphExpectingOaEmail($workOrder);

        $this->post(route('vendor.portal.invoice', 'token-acme'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'filename' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice'),
        ])->assertRedirect();
    }

    public function test_turnover_invoice_from_staff_upload_emails_operation_accounting(): void
    {
        Storage::fake('public');
        Http::fake();

        Role::findOrCreate('woc', 'web');
        $woc = User::factory()->create();
        $woc->assignRole('woc');

        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder('Turnover');
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->mockGraphExpectingOaEmail($workOrder);

        $this->actingAs($woc)->post(route('api.invoices.store'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'is_publish_to_owner_portal' => 'No',
            'is_publish_to_tenant_portal' => 'No',
            'filename' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice'),
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_non_turnover_invoice_does_not_email_operation_accounting(): void
    {
        Storage::fake('public');
        Http::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder('Standard');
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->mockGraphExpectingNoEmail();

        $this->post(route('vendor.portal.invoice', 'token-acme'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'filename' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice'),
        ])->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'work_order_id' => $workOrder->id,
            'amount' => '325.00',
        ]);
    }

    public function test_gate_off_sends_no_email_but_still_stores_the_invoice(): void
    {
        config(['services.operation_accounting.turnover_invoice_notifications' => false]);
        Storage::fake('public');
        Http::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder('Turnover');
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->mockGraphExpectingNoEmail();

        $this->post(route('vendor.portal.invoice', 'token-acme'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'filename' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice'),
        ])->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'work_order_id' => $workOrder->id,
            'amount' => '325.00',
        ]);
    }

    public function test_turnover_by_category_also_notifies(): void
    {
        Storage::fake('public');
        Http::fake();

        $vendor = $this->makeVendor();
        $workOrder = $this->makeWorkOrder('Standard');
        $workOrder->update(['category' => 'Turnover']);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-acme']);

        $this->mockGraphExpectingOaEmail($workOrder);

        $this->post(route('vendor.portal.invoice', 'token-acme'), [
            'title' => 'Labor and parts',
            'amount' => '325.00',
            'filename' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice'),
        ])->assertRedirect();
    }
}
