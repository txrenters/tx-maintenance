<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobInvoice;
use App\Models\JobberProperty;
use App\Models\Scopes\NotArchivedScope;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An invoice is an accounting document: the Delete action archives it instead,
 * keeping both the row and the uploaded file so the office can put it back.
 */
class InvoiceArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function newStatus(): ServiceStatus
    {
        return ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);
    }

    private function makeVendor(?User $user = null): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Ace Repairs',
            'email' => 'ace'.uniqid().'@example.com',
            'user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        $workOrder = WorkOrder::factory()->create(['service_status_id' => $this->newStatus()->id]);

        return Invoice::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
            'title' => 'Fence invoice',
            'amount' => 250,
            'filename' => 'invoices/fence.pdf',
            'filetype' => 'application/pdf',
        ], $overrides));
    }

    private function makeJobberInvoice(array $overrides = []): JobberJobInvoice
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

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '4001',
            'title' => 'Fence repair',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberJobInvoice::query()->create(array_merge([
            'jobber_job_id' => $job->id,
            'title' => 'Jobber fence invoice',
            'amount' => 300,
            'filename' => 'jobber-invoices/fence.pdf',
        ], $overrides));
    }

    /* Work-order invoices --------------------------------------------------- */

    public function test_archiving_keeps_the_row_rather_than_deleting_it(): void
    {
        $invoice = $this->makeInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertNotNull(
            Invoice::withoutGlobalScope(NotArchivedScope::class)->find($invoice->id)?->archived_at
        );
    }

    public function test_archiving_keeps_the_uploaded_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('invoices/fence.pdf', 'the original document');

        $invoice = $this->makeInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        // The whole point of archiving: a restored invoice still opens.
        Storage::disk('public')->assertExists('invoices/fence.pdf');
    }

    public function test_an_archived_invoice_is_hidden_from_the_invoices_page(): void
    {
        $invoice = $this->makeInvoice(['title' => 'Archived Fence Invoice']);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $response = $this->actingAs($admin)->get(route('invoices.index'))->assertOk();

        $this->assertStringNotContainsString('Archived Fence Invoice', $response->getContent());
    }

    public function test_the_office_can_list_archived_invoices(): void
    {
        $invoice = $this->makeInvoice(['title' => 'Archived Fence Invoice']);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $response = $this->actingAs($admin)
            ->get(route('invoices.index', ['archived' => 1]))
            ->assertOk();

        $this->assertStringContainsString('Archived Fence Invoice', $response->getContent());
    }

    public function test_a_vendor_asking_for_the_archive_still_does_not_see_it(): void
    {
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $vendor = $this->makeVendor($vendorUser);
        $invoice = $this->makeInvoice(['vendor_id' => $vendor->id, 'title' => 'Archived Fence Invoice']);

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $response = $this->actingAs($vendorUser)
            ->get(route('invoices.index', ['archived' => 1]))
            ->assertOk();

        $this->assertStringNotContainsString('Archived Fence Invoice', $response->getContent());
    }

    public function test_the_office_can_restore_an_archived_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));
        $this->actingAs($admin)->post(route('api.invoices.restore', $invoice->id));

        $this->assertNull(Invoice::find($invoice->id)?->archived_at);
    }

    public function test_a_vendor_cannot_restore_an_invoice(): void
    {
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $vendor = $this->makeVendor($vendorUser);
        $invoice = $this->makeInvoice(['vendor_id' => $vendor->id]);

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $this->actingAs($vendorUser)
            ->post(route('api.invoices.restore', $invoice->id))
            ->assertForbidden();

        $this->assertNotNull(
            Invoice::withoutGlobalScope(NotArchivedScope::class)->find($invoice->id)?->archived_at
        );
    }

    public function test_an_archived_invoice_is_hidden_from_the_work_order(): void
    {
        $invoice = $this->makeInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('api.invoices.destroy', $invoice->id));

        $workOrder = WorkOrder::withoutGlobalScopes()->find($invoice->work_order_id);

        $this->assertCount(0, $workOrder->invoices()->get());
    }

    /* Jobber job invoices --------------------------------------------------- */

    public function test_archiving_a_jobber_invoice_keeps_the_row_and_the_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('jobber-invoices/fence.pdf', 'the original document');

        $invoice = $this->makeJobberInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('jobber.invoices.destroy', $invoice->id));

        $this->assertDatabaseHas('jobber_job_invoices', ['id' => $invoice->id]);
        $this->assertNotNull(
            JobberJobInvoice::withoutGlobalScope(NotArchivedScope::class)->find($invoice->id)?->archived_at
        );
        Storage::disk('public')->assertExists('jobber-invoices/fence.pdf');
    }

    public function test_an_archived_jobber_invoice_is_hidden_from_the_job(): void
    {
        $invoice = $this->makeJobberInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('jobber.invoices.destroy', $invoice->id));

        $job = Jobber::query()->find($invoice->jobber_job_id);

        $this->assertCount(0, $job->jobInvoices()->get());
    }

    public function test_staff_can_restore_a_jobber_invoice(): void
    {
        $invoice = $this->makeJobberInvoice();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->delete(route('jobber.invoices.destroy', $invoice->id));
        $this->actingAs($admin)->post(route('jobber.invoices.restore', $invoice->id));

        $this->assertNull(JobberJobInvoice::find($invoice->id)?->archived_at);
    }

    public function test_a_vendor_cannot_archive_a_jobber_invoice(): void
    {
        $invoice = $this->makeJobberInvoice();
        $vendorUser = User::factory()->create()->assignRole('vendor');

        $this->actingAs($vendorUser)
            ->delete(route('jobber.invoices.destroy', $invoice->id))
            ->assertForbidden();

        $this->assertNull(JobberJobInvoice::find($invoice->id)?->archived_at);
    }
}
