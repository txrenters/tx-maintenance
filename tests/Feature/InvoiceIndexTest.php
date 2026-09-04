<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Vendor, 1: WorkOrder, 2: Invoice}
     */
    private function makeVendorInvoice(string $vendorName, int $workOrderNo, string $title): array
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.$workOrderNo,
            'name' => $vendorName,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => $workOrderNo,
            'status' => 'Open',
        ]);

        $invoice = Invoice::query()->create([
            'title' => $title,
            'filename' => 'invoices/'.$workOrderNo.'.pdf',
            'filetype' => 'application/pdf',
            'amount' => 227.33,
            'status' => 'approved',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        return [$vendor, $workOrder, $invoice];
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_index_exposes_the_work_order_id_and_upload_timestamp(): void
    {
        [, $workOrder, $invoice] = $this->makeVendorInvoice('Oops Steam Cleaning LLC', 43796, 'Oops! Steam Cleaning');

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);

        $this->assertNotNull($row, 'The invoice should be listed.');
        $this->assertSame($workOrder->id, $row['work_order_id'], 'The work order id is needed to build the link.');
        $this->assertSame(43796, $row['work_order_no']);
        // The raw timestamp must survive so the front end can show a time, not just a date.
        $this->assertSame($invoice->created_at->toIso8601String(), $row['created_at']);
    }

    public function test_search_matches_the_vendor_name(): void
    {
        [, , $matching] = $this->makeVendorInvoice('SO Fresh Cleaning LLC', 44001, 'Cleaning invoice');
        [, , $other] = $this->makeVendorInvoice('Oops Steam Cleaning LLC', 44002, 'Steam invoice');

        $response = $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', ['search' => 'so fre']));

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertTrue($ids->contains($matching->id), 'Searching a vendor name should find their invoice.');
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_search_matches_the_work_order_number_and_title(): void
    {
        [, , $matching] = $this->makeVendorInvoice('Alpha Services LLC', 43796, 'Alpha invoice');
        [, , $other] = $this->makeVendorInvoice('Beta Services LLC', 12345, 'Beta invoice');

        $admin = $this->actingAsAdmin();

        $byNumber = $this->actingAs($admin)->get(route('invoices.index', ['search' => '43796']));
        $byNumber->assertOk();
        $numberIds = collect($byNumber->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($numberIds->contains($matching->id));
        $this->assertFalse($numberIds->contains($other->id));

        $byTitle = $this->actingAs($admin)->get(route('invoices.index', ['search' => 'Alpha invoice']));
        $byTitle->assertOk();
        $titleIds = collect($byTitle->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($titleIds->contains($matching->id));
        $this->assertFalse($titleIds->contains($other->id));
    }

    public function test_a_vendor_searching_another_vendor_name_sees_nothing(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$ownVendor, , $ownInvoice] = $this->makeVendorInvoice('Alpha Services LLC', 45001, 'Alpha invoice');
        [, , $foreignInvoice] = $this->makeVendorInvoice('Beta Services LLC', 45002, 'Beta invoice');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $ownVendor->update(['user_id' => $vendorUser->id]);

        // The OR clauses must stay inside their own group, or the role scope leaks.
        $response = $this->actingAs($vendorUser)
            ->get(route('invoices.index', ['search' => 'Beta Services']));

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertFalse($ids->contains($foreignInvoice->id), 'A vendor must never see another vendor invoice.');
        $this->assertFalse($ids->contains($ownInvoice->id), 'Their own invoice does not match this search either.');
    }

    public function test_an_invoice_without_a_vendor_still_renders(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 46001, 'status' => 'Open']);

        $invoice = Invoice::query()->create([
            'title' => 'Orphaned invoice',
            'filename' => 'invoices/46001.pdf',
            'filetype' => 'application/pdf',
            'amount' => 100.00,
            'status' => 'approved',
            'work_order_id' => $workOrder->id,
            'vendor_id' => null,
        ]);

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);

        $this->assertNotNull($row);
        $this->assertNull($row['vendor']);
    }
}
