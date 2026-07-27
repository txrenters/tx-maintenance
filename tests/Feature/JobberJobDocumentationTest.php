<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobAttachment;
use App\Models\JobberJobInvoice;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberJobDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeJob(): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => 'Outside Client',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '4001',
            'title' => 'Fence repair',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Fence Pros',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_staff_can_upload_photos_without_touching_propertyware(): void
    {
        Storage::fake('public');
        Queue::fake();

        $job = $this->makeJob();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('jobber.attachments.store', $job->id), [
            'title' => 'Before the repair',
            'type' => 'before',
            'files' => [UploadedFile::fake()->image('before.jpg')],
        ])->assertRedirect();

        $this->assertDatabaseHas('jobber_job_attachments', [
            'jobber_job_id' => $job->id,
            'type' => 'before',
            'uploaded_via' => 'staff',
        ]);

        Queue::assertNotPushed(UploadAttachment::class);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_staff_can_delete_a_photo(): void
    {
        Storage::fake('public');

        $job = $this->makeJob();
        $admin = User::factory()->create()->assignRole('admin');

        $attachment = JobberJobAttachment::query()->create([
            'jobber_job_id' => $job->id,
            'title' => 'Temp',
            'filename' => 'jobber-attachments/temp.jpg',
        ]);

        $this->actingAs($admin)
            ->delete(route('jobber.attachments.destroy', $attachment->id))
            ->assertRedirect();

        $this->assertDatabaseCount('jobber_job_attachments', 0);
    }

    public function test_a_tenant_cannot_upload_photos_to_a_jobber_job(): void
    {
        Storage::fake('public');

        $job = $this->makeJob();
        $tenant = User::factory()->create()->assignRole('tenant');

        $this->actingAs($tenant)->post(route('jobber.attachments.store', $job->id), [
            'title' => 'Nope',
            'files' => [UploadedFile::fake()->image('nope.jpg')],
        ])->assertForbidden();

        $this->assertDatabaseCount('jobber_job_attachments', 0);
    }

    public function test_staff_invoice_upload_never_calls_the_propertyware_invoice_sync(): void
    {
        Storage::fake('public');

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('uploadVendorInvoice');
        });

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('jobber.invoices.store', $job->id), [
            'title' => 'Fence invoice',
            'amount' => 300,
            'vendor_id' => $vendor->id,
            'filename' => UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('jobber_job_invoices', [
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_an_invoice_cannot_be_filed_against_an_unassigned_vendor(): void
    {
        Storage::fake('public');

        $job = $this->makeJob();
        $stranger = $this->makeVendor();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('jobber.invoices.store', $job->id), [
            'title' => 'Bogus invoice',
            'amount' => 100,
            'vendor_id' => $stranger->id,
            'filename' => UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
        ])->assertSessionHasErrors('vendor_id');

        $this->assertDatabaseCount('jobber_job_invoices', 0);
    }

    public function test_staff_can_decline_and_re_approve_an_invoice(): void
    {
        $invoice = JobberJobInvoice::query()->create([
            'jobber_job_id' => $this->makeJob()->id,
            'title' => 'Fence invoice',
            'amount' => 300,
            'filename' => 'jobber-invoices/fence.pdf',
        ]);

        // Matching the work-order flow, an invoice is stored already approved
        // (read back from the database — Eloquent doesn't hydrate DB defaults).
        $this->assertSame('approved', $invoice->fresh()->status);

        $accounting = User::factory()->create()->assignRole('accounting');

        $this->actingAs($accounting)
            ->patch(route('jobber.invoices.update', $invoice->id), ['status' => 'decline'])
            ->assertRedirect();

        $this->assertSame('decline', $invoice->fresh()->status);

        $this->actingAs($accounting)
            ->patch(route('jobber.invoices.update', $invoice->id), ['status' => 'approved']);

        $this->assertSame('approved', $invoice->fresh()->status);
    }

    public function test_an_arbitrary_invoice_status_is_rejected(): void
    {
        $invoice = JobberJobInvoice::query()->create([
            'jobber_job_id' => $this->makeJob()->id,
            'title' => 'Fence invoice',
            'amount' => 300,
            'filename' => 'jobber-invoices/fence.pdf',
        ]);

        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->patch(route('jobber.invoices.update', $invoice->id), ['status' => 'whatever'])
            ->assertSessionHasErrors('status');

        $this->assertSame('approved', $invoice->fresh()->status);
    }

    public function test_a_vendor_cannot_approve_or_decline_an_invoice(): void
    {
        $invoice = JobberJobInvoice::query()->create([
            'jobber_job_id' => $this->makeJob()->id,
            'title' => 'Fence invoice',
            'amount' => 300,
            'filename' => 'jobber-invoices/fence.pdf',
        ]);

        $vendorUser = User::factory()->create()->assignRole('vendor');

        $this->actingAs($vendorUser)
            ->patch(route('jobber.invoices.update', $invoice->id), ['status' => 'decline'])
            ->assertForbidden();

        $this->assertSame('approved', $invoice->fresh()->status);
    }

    public function test_jobber_invoices_do_not_appear_on_the_work_order_invoice_page(): void
    {
        $job = $this->makeJob();
        $vendor = $this->makeVendor();

        JobberJobInvoice::query()->create([
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
            'title' => 'Jobber Only Invoice',
            'amount' => 250,
            'filename' => 'jobber-invoices/only.pdf',
        ]);

        $admin = User::factory()->create()->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('invoices.index'))->assertOk();

        $this->assertStringNotContainsString('Jobber Only Invoice', $response->getContent());
    }
}
