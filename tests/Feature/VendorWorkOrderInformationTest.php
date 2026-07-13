<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Mail\VendorServiceRequestMail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorWorkOrderInformationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(array $overrides = [], ?string $userPhone = null): Vendor
    {
        $user = User::factory()->create($userPhone ? ['phone' => $userPhone] : []);

        return Vendor::query()->create(array_merge([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1, 100000),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'vendor@example.com',
            'user_id' => $user->id,
        ], $overrides));
    }

    public function test_pdf_renders_to_valid_pdf_bytes(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43339,
            'description' => 'The garage plug is tripped and will not reset.',
        ]);

        $bytes = app(WorkOrderInformationPdf::class)->render($workOrder);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_job_emails_vendor_and_uploads_pdf_to_propertyware(): void
    {
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 987654321], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 987654321], 200),
        ]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com']);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43339]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        Mail::assertSent(VendorServiceRequestMail::class, function ($mail) use ($vendor) {
            return $mail->hasTo($vendor->email);
        });

        Http::assertSent(fn ($request) => $request->url() === 'https://api.propertyware.com/pw/api/rest/v1/docs'
            && $request->method() === 'POST');

        // The uploaded PDF is recorded locally so it shows in the Attachments tab.
        $this->assertDatabaseHas('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 987654321,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);
    }

    public function test_job_uploads_to_propertyware_even_without_vendor_email(): void
    {
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-9'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-9'], 200),
        ]);

        $vendor = $this->makeVendor(['email' => null]);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-xyz']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        Mail::assertNothingSent();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.propertyware.com/pw/api/rest/v1/docs')
            && $request->method() === 'POST');
    }

    public function test_job_texts_vendor_and_logs_woc_conversation(): void
    {
        Bus::fake();          // keep the nested Twilio job from actually sending
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-77'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-77'], 200),
        ]);

        // No coordinator on the work order, so the WOC number falls back to config.
        config(['services.twilio.maintenance_number' => '+15550001111']);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com'], '3255550101');
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43339,
            'priority' => 'Med',
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        // Saved into the WOC↔Vendor thread, sent as the WOC (its number), to the vendor.
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'sender_number' => '+15550001111',
            'receiver_number' => '+13255550101',
            'read_by_vendor' => false,
        ]);

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_job_does_not_text_vendor_without_a_phone(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-1'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-1'], 200),
        ]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com']); // user has no phone
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseCount('work_order_conversations', 0);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }
}
