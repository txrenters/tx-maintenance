<?php

namespace Tests\Feature;

use App\Models\Building;
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

    public function test_sorting_by_vendor_orders_alphabetically(): void
    {
        $this->makeVendorInvoice('Zulu Services LLC', 47001, 'Zulu invoice');
        $this->makeVendorInvoice('Alpha Services LLC', 47002, 'Alpha invoice');
        $this->makeVendorInvoice('Mike Services LLC', 47003, 'Mike invoice');

        $admin = $this->actingAsAdmin();

        $asc = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'vendor', 'direction' => 'asc']));
        $asc->assertOk();
        $this->assertSame(
            ['Alpha Services LLC', 'Mike Services LLC', 'Zulu Services LLC'],
            collect($asc->viewData('page')['props']['invoices']['data'])->pluck('vendor')->all()
        );

        $desc = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'vendor', 'direction' => 'desc']));
        $desc->assertOk();
        $this->assertSame(
            ['Zulu Services LLC', 'Mike Services LLC', 'Alpha Services LLC'],
            collect($desc->viewData('page')['props']['invoices']['data'])->pluck('vendor')->all()
        );
    }

    public function test_sorting_by_name_orders_alphabetically(): void
    {
        $this->makeVendorInvoice('Alpha Services LLC', 46101, 'Zebra invoice');
        $this->makeVendorInvoice('Beta Services LLC', 46102, 'Apple invoice');
        $this->makeVendorInvoice('Gamma Services LLC', 46103, 'Mango invoice');

        $admin = $this->actingAsAdmin();

        $asc = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'name', 'direction' => 'asc']));
        $asc->assertOk();
        $this->assertSame(
            ['Apple invoice', 'Mango invoice', 'Zebra invoice'],
            collect($asc->viewData('page')['props']['invoices']['data'])->pluck('title')->all()
        );

        $desc = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'name', 'direction' => 'desc']));
        $desc->assertOk();
        $this->assertSame(
            ['Zebra invoice', 'Mango invoice', 'Apple invoice'],
            collect($desc->viewData('page')['props']['invoices']['data'])->pluck('title')->all()
        );
    }

    public function test_sorting_by_upload_time_orders_recent_or_oldest_first(): void
    {
        [, , $oldest] = $this->makeVendorInvoice('Alpha Services LLC', 48001, 'Oldest');
        [, , $newest] = $this->makeVendorInvoice('Beta Services LLC', 48002, 'Newest');

        $oldest->forceFill(['created_at' => '2026-09-01 12:00:00'])->save();
        $newest->forceFill(['created_at' => '2026-09-04 12:00:00'])->save();

        $admin = $this->actingAsAdmin();

        $recent = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'uploaded', 'direction' => 'desc']));
        $recent->assertOk();
        $this->assertSame(
            [$newest->id, $oldest->id],
            collect($recent->viewData('page')['props']['invoices']['data'])->pluck('id')->all()
        );

        $old = $this->actingAs($admin)->get(route('invoices.index', ['sort' => 'uploaded', 'direction' => 'asc']));
        $old->assertOk();
        $this->assertSame(
            [$oldest->id, $newest->id],
            collect($old->viewData('page')['props']['invoices']['data'])->pluck('id')->all()
        );
    }

    public function test_an_unknown_sort_column_is_ignored(): void
    {
        $this->makeVendorInvoice('Alpha Services LLC', 49001, 'Alpha invoice');

        $response = $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', ['sort' => 'filename); drop table invoices;--']));

        $response->assertOk();
        $this->assertNull($response->viewData('page')['props']['sort']);
    }

    public function test_the_occupancy_filter_separates_vacant_from_occupied(): void
    {
        [, $turnoverWo, $turnover] = $this->makeVendorInvoice('Alpha Services LLC', 50001, 'Turnover invoice');
        $turnoverWo->forceFill(['type' => 'Turnover'])->save();

        [, $rekeyWo, $rekey] = $this->makeVendorInvoice('Beta Services LLC', 50002, 'Rekey invoice');
        $rekeyWo->forceFill(['category' => 'Re-Key'])->save();

        [, $toggledWo, $toggled] = $this->makeVendorInvoice('Gamma Services LLC', 50003, 'Toggled invoice');
        $toggledWo->forceFill(['skip_automated_tasks' => true])->save();

        [, , $occupied] = $this->makeVendorInvoice('Delta Services LLC', 50004, 'Occupied invoice');

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($turnover->id), 'A turnover job is vacant.');
        $this->assertTrue($vacantIds->contains($rekey->id), 'A re-key job is vacant.');
        $this->assertTrue($vacantIds->contains($toggled->id), 'The Vacant toggle marks it vacant.');
        $this->assertFalse($vacantIds->contains($occupied->id));

        $occupiedResponse = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'occupied']));
        $occupiedResponse->assertOk();
        $occupiedIds = collect($occupiedResponse->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertSame([$occupied->id], $occupiedIds->all(), 'Only the occupied invoice remains.');
    }

    public function test_the_index_shows_the_property_address(): void
    {
        $building = Building::query()->create([
            'propertyware_id' => 9911,
            'name' => '1532A',
            'address' => '1532A Creekside Ln',
        ]);

        [, $workOrder, $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 51001, 'Alpha invoice');
        $workOrder->forceFill(['building_id' => $building->propertyware_id])->save();

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);

        $this->assertSame('1532A Creekside Ln', $row['address']);
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
