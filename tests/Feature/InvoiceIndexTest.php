<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Vendor, 1: WorkOrder, 2: Invoice}
     */
    private function makeVendorInvoice(string $vendorName, int $workOrderNo, string $title, ?string $invoiceNumber = null): array
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
            'invoice_number' => $invoiceNumber,
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

    public function test_the_invoice_title_is_still_sent_for_the_download_label(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 46101, 'THMP_Invoice INV-5087');

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);

        // The File column shows an icon, and names the invoice on hover.
        $this->assertSame('THMP_Invoice INV-5087', $row['title']);
        $this->assertNotEmpty($row['file']);
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

    public function test_the_occupancy_filter_matches_propertyware_trailing_spaces(): void
    {
        // PropertyWare's picklists carry trailing spaces ("HVAC "), and
        // isVacant() trims before comparing. The SQL filter has to agree.
        [, $turnoverWo, $turnover] = $this->makeVendorInvoice('Alpha Services LLC', 50101, 'Padded turnover');
        $turnoverWo->forceFill(['type' => 'Turnover '])->save();

        [, $rekeyWo, $rekey] = $this->makeVendorInvoice('Beta Services LLC', 50102, 'Padded rekey');
        $rekeyWo->forceFill(['category' => 'Re-key '])->save();

        [, , $occupied] = $this->makeVendorInvoice('Gamma Services LLC', 50103, 'Occupied invoice');

        $admin = $this->actingAsAdmin();

        $this->assertTrue($turnoverWo->fresh()->isVacant(), 'A padded turnover is vacant in PHP.');
        $this->assertTrue($rekeyWo->fresh()->isVacant(), 'A padded re-key is vacant in PHP.');

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($turnover->id), 'And vacant in SQL.');
        $this->assertTrue($vacantIds->contains($rekey->id));
        $this->assertFalse($vacantIds->contains($occupied->id));

        $occupiedResponse = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'occupied']));
        $occupiedResponse->assertOk();
        $occupiedIds = collect($occupiedResponse->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertSame([$occupied->id], $occupiedIds->all(), 'The padded rows are not counted as occupied.');
    }

    public function test_sorting_by_address_falls_back_to_the_building_name(): void
    {
        // propertyAddress() falls back to the name when the address is blank;
        // the sort key has to make the same choice or the order looks wrong.
        $blank = Building::query()->create([
            'propertyware_id' => 9971,
            'name' => 'AAA Building',
            'address' => '   ',
        ]);
        $real = Building::query()->create([
            'propertyware_id' => 9972,
            'name' => 'ZZZ Building',
            'address' => 'MMM Street',
        ]);

        [, $blankWo, $blankInvoice] = $this->makeVendorInvoice('Alpha Services LLC', 51101, 'Blank address');
        $blankWo->forceFill(['building_id' => $blank->propertyware_id])->save();

        [, $realWo, $realInvoice] = $this->makeVendorInvoice('Beta Services LLC', 51102, 'Real address');
        $realWo->forceFill(['building_id' => $real->propertyware_id])->save();

        $response = $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', ['sort' => 'address', 'direction' => 'asc']));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['invoices']['data']);

        $this->assertSame('AAA Building', $rows->firstWhere('id', $blankInvoice->id)['address']);
        $this->assertSame(
            [$blankInvoice->id, $realInvoice->id],
            $rows->pluck('id')->all(),
            'AAA Building sorts before MMM Street, so the sort used the fallback too.'
        );
    }

    public function test_a_propertyware_row_with_no_lease_counts_as_vacant(): void
    {
        // WO#44032's shape: a between-tenant home carrying neither the Vacant
        // toggle nor a turnover/re-key type. hasNoTenant() already treats these
        // as having nobody to contact, so the filter has to agree.
        [, $noLeaseWo, $noLease] = $this->makeVendorInvoice('Oops Steam Cleaning LLC', 54001, 'No lease invoice');
        $noLeaseWo->forceFill([
            'propertyware_id' => 990001,
            'lease_id' => null,
            'type' => 'Repair',
            'category' => 'Cleaning',
            'skip_automated_tasks' => false,
        ])->save();

        [, $leasedWo, $leased] = $this->makeVendorInvoice('Alpha Services LLC', 54002, 'Leased invoice');
        $leasedWo->forceFill([
            'propertyware_id' => 990002,
            'lease_id' => 5551,
            'type' => 'Repair',
        ])->save();

        $this->assertTrue($noLeaseWo->fresh()->hasNoTenant(), 'Nobody lives there in PHP.');
        $this->assertFalse($leasedWo->fresh()->hasNoTenant());

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($noLease->id), 'And vacant in SQL.');
        $this->assertFalse($vacantIds->contains($leased->id));

        $occupied = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'occupied']));
        $occupied->assertOk();
        $occupiedIds = collect($occupied->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($occupiedIds->contains($leased->id));
        $this->assertFalse($occupiedIds->contains($noLease->id));
    }

    public function test_app_created_rows_without_a_lease_stay_occupied(): void
    {
        // Rows the app makes itself never carry a lease, so a missing one says
        // nothing about who lives there.
        [, $localWo, $local] = $this->makeVendorInvoice('Alpha Services LLC', 54101, 'Local invoice');
        $localWo->forceFill(['propertyware_id' => null, 'lease_id' => null, 'type' => 'Repair'])->save();

        [, $portalWo, $portal] = $this->makeVendorInvoice('Beta Services LLC', 54102, 'Portal invoice');
        $portalWo->forceFill([
            'propertyware_id' => 990102,
            'lease_id' => null,
            'source' => 'Tenant Portal',
            'type' => 'Repair',
        ])->save();

        $occupied = $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', ['occupancy' => 'occupied']));

        $occupied->assertOk();
        $ids = collect($occupied->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertTrue($ids->contains($local->id), 'A local row is not evidence of vacancy.');
        $this->assertTrue($ids->contains($portal->id), 'A tenant wrote the portal request, so someone lives there.');
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

    public function test_the_date_range_filters_to_whole_central_days(): void
    {
        [, , $june] = $this->makeVendorInvoice('Alpha Services LLC', 53001, 'June invoice');
        [, , $julyFirst] = $this->makeVendorInvoice('Beta Services LLC', 53002, 'July 1 invoice');
        [, , $julyFifth] = $this->makeVendorInvoice('Gamma Services LLC', 53003, 'July 5 invoice');
        [, , $julyLast] = $this->makeVendorInvoice('Delta Services LLC', 53004, 'July 31 invoice');
        [, , $august] = $this->makeVendorInvoice('Echo Services LLC', 53005, 'August invoice');

        // Stored in UTC. The July 1st row is 12:30 AM Central on the 1st, and
        // the 31st row is 11:30 PM Central: both must fall inside July.
        $june->forceFill(['created_at' => '2026-06-30 12:00:00'])->save();
        $julyFirst->forceFill(['created_at' => '2026-07-01 05:30:00'])->save();
        $julyFifth->forceFill(['created_at' => '2026-07-05 18:00:00'])->save();
        $julyLast->forceFill(['created_at' => '2026-08-01 04:30:00'])->save();
        $august->forceFill(['created_at' => '2026-08-02 12:00:00'])->save();

        $admin = $this->actingAsAdmin();

        $july = $this->actingAs($admin)->get(route('invoices.index', [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]));
        $july->assertOk();
        $julyIds = collect($july->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertTrue($julyIds->contains($julyFirst->id), 'Just after midnight Central on the 1st is in July.');
        $this->assertTrue($julyIds->contains($julyFifth->id));
        $this->assertTrue($julyIds->contains($julyLast->id), 'Late on the 31st Central is still July.');
        $this->assertFalse($julyIds->contains($june->id));
        $this->assertFalse($julyIds->contains($august->id));

        // A few days inside the month.
        $firstWeek = $this->actingAs($admin)->get(route('invoices.index', [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-05',
        ]));
        $firstWeek->assertOk();
        $weekIds = collect($firstWeek->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($weekIds->contains($julyFirst->id));
        $this->assertTrue($weekIds->contains($julyFifth->id));
        $this->assertFalse($weekIds->contains($julyLast->id));
    }

    public function test_the_date_range_survives_alongside_the_other_filters(): void
    {
        [, , $matching] = $this->makeVendorInvoice('Oops Steam Cleaning LLC', 53101, 'Match');
        [, , $wrongDate] = $this->makeVendorInvoice('Oops Steam Cleaning LLC', 53102, 'Wrong date');
        [, , $wrongVendor] = $this->makeVendorInvoice('Alpha Services LLC', 53103, 'Wrong vendor');

        $matching->forceFill(['created_at' => '2026-07-10 12:00:00'])->save();
        $wrongDate->forceFill(['created_at' => '2026-06-10 12:00:00'])->save();
        $wrongVendor->forceFill(['created_at' => '2026-07-11 12:00:00'])->save();

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index', [
            'search' => 'oops',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]));

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertSame([$matching->id], $ids->all(), 'The search and the date range both apply.');
        $this->assertSame('2026-07-01', $response->viewData('page')['props']['filter']['start_date']);
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', [
                'start_date' => '2026-07-31',
                'end_date' => '2026-07-01',
            ]))
            ->assertSessionHasErrors('end_date');
    }

    public function test_staff_can_mark_an_invoice_posted_and_unpost_it(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52001, 'Alpha invoice');

        $staff = $this->actingAsAdmin();

        $this->actingAs($staff)
            ->post(route('invoices.posted.store', $invoice->id))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertNotNull($invoice->posted_at);
        $this->assertSame($staff->id, $invoice->posted_by_user_id);

        $this->actingAs($staff)
            ->delete(route('invoices.posted.destroy', $invoice->id))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertNull($invoice->posted_at);
        $this->assertNull($invoice->posted_by_user_id);
    }

    public function test_accounting_can_mark_an_invoice_posted(): void
    {
        Role::findOrCreate('accounting', 'web');

        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52101, 'Alpha invoice');

        $accounting = User::factory()->create();
        $accounting->assignRole('accounting');

        $this->actingAs($accounting)
            ->post(route('invoices.posted.store', $invoice->id))
            ->assertRedirect();

        $this->assertNotNull($invoice->refresh()->posted_at);
    }

    public function test_re_posting_keeps_the_original_record(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52201, 'Alpha invoice');

        $first = $this->actingAsAdmin();
        $this->actingAs($first)->post(route('invoices.posted.store', $invoice->id));

        $originalPostedAt = $invoice->refresh()->posted_at;

        $second = $this->actingAsAdmin();
        $this->actingAs($second)->post(route('invoices.posted.store', $invoice->id));

        $invoice->refresh();
        $this->assertSame($first->id, $invoice->posted_by_user_id, 'The first poster is kept.');
        $this->assertEquals($originalPostedAt, $invoice->posted_at);
    }

    public function test_a_vendor_cannot_mark_their_own_invoice_posted(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$vendor, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52301, 'Alpha invoice');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor->update(['user_id' => $vendorUser->id]);

        // InvoiceScope lets them read this invoice, so the write needs its own gate.
        $this->actingAs($vendorUser)
            ->post(route('invoices.posted.store', $invoice->id))
            ->assertForbidden();

        $this->assertNull($invoice->refresh()->posted_at);
    }

    public function test_the_row_carries_the_vendor_invoice_number(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 55001, 'Alpha invoice', 'INV-5087');

        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);

        $this->assertSame('INV-5087', $row['invoice_number']);
    }

    public function test_search_matches_the_invoice_number(): void
    {
        [, , $matching] = $this->makeVendorInvoice('Alpha Services LLC', 55101, 'Alpha invoice', 'INV-5087');
        [, , $other] = $this->makeVendorInvoice('Beta Services LLC', 55102, 'Beta invoice', 'INV-5088');

        $response = $this->actingAs($this->actingAsAdmin())
            ->get(route('invoices.index', ['search' => '5087']));

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertTrue($ids->contains($matching->id), 'Accounting looks an invoice up by the number on the bill.');
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_a_vendor_searching_another_vendors_invoice_number_sees_nothing(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$ownVendor, , $ownInvoice] = $this->makeVendorInvoice('Alpha Services LLC', 55201, 'Alpha invoice', 'INV-1');
        [, , $foreignInvoice] = $this->makeVendorInvoice('Beta Services LLC', 55202, 'Beta invoice', 'INV-9999');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $ownVendor->update(['user_id' => $vendorUser->id]);

        // The number joins the grouped OR clauses, so it must stay inside the role restriction.
        $response = $this->actingAs($vendorUser)
            ->get(route('invoices.index', ['search' => '9999']));

        $response->assertOk();
        $ids = collect($response->viewData('page')['props']['invoices']['data'])->pluck('id');

        $this->assertFalse($ids->contains($foreignInvoice->id), 'A vendor must never match another vendor invoice by number.');
        $this->assertFalse($ids->contains($ownInvoice->id));
    }

    public function test_staff_can_type_and_clear_the_invoice_number_from_the_list(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 55301, 'Alpha invoice');

        $staff = $this->actingAsAdmin();

        $this->actingAs($staff)
            ->patch(route('invoices.number.update', $invoice->id), ['invoice_number' => '  INV-5087  '])
            ->assertRedirect();

        $this->assertSame('INV-5087', $invoice->refresh()->invoice_number, 'Surrounding spaces are not part of the number.');

        $this->actingAs($staff)
            ->patch(route('invoices.number.update', $invoice->id), ['invoice_number' => ''])
            ->assertRedirect();

        $this->assertNull($invoice->refresh()->invoice_number, 'A blank submission clears it.');
    }

    public function test_an_overlong_invoice_number_is_rejected(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 55401, 'Alpha invoice', 'INV-1');

        $this->actingAs($this->actingAsAdmin())
            ->from(route('invoices.index'))
            ->patch(route('invoices.number.update', $invoice->id), ['invoice_number' => str_repeat('9', 101)])
            ->assertSessionHasErrors('invoice_number');

        $this->assertSame('INV-1', $invoice->refresh()->invoice_number);
    }

    public function test_a_vendor_cannot_change_their_own_invoice_number(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$vendor, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 55501, 'Alpha invoice', 'INV-1');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor->update(['user_id' => $vendorUser->id]);

        // InvoiceScope lets them read this invoice, so the write needs its own gate.
        $this->actingAs($vendorUser)
            ->patch(route('invoices.number.update', $invoice->id), ['invoice_number' => 'INV-2'])
            ->assertForbidden();

        $this->assertSame('INV-1', $invoice->refresh()->invoice_number);
    }

    public function test_the_staff_upload_keeps_the_invoice_number(): void
    {
        Storage::fake('public');
        Http::fake();

        [$vendor, $workOrder] = $this->makeVendorInvoice('Alpha Services LLC', 55601, 'Earlier invoice');
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-alpha']);

        $this->actingAs($this->actingAsAdmin())->post(route('api.invoices.store'), [
            'title' => 'Labor and parts',
            'invoice_number' => 'INV-5087',
            'amount' => '325.00',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'is_publish_to_owner_portal' => 'No',
            'is_publish_to_tenant_portal' => 'No',
            'filename' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'work_order_id' => $workOrder->id,
            'title' => 'Labor and parts',
            'invoice_number' => 'INV-5087',
        ]);
    }

    public function test_the_index_reports_the_posted_state_and_who_posted_it(): void
    {
        [, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52401, 'Alpha invoice');

        $staff = $this->actingAsAdmin();
        $this->actingAs($staff)->post(route('invoices.posted.store', $invoice->id));

        $response = $this->actingAs($staff)->get(route('invoices.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $row = collect($props['invoices']['data'])->firstWhere('id', $invoice->id);

        $this->assertNotNull($row['posted_at']);
        $this->assertSame($staff->name, $row['posted_by']);
        $this->assertTrue($props['canPost']);
    }

    public function test_a_vendor_is_not_offered_the_posted_checkbox(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$vendor] = $this->makeVendorInvoice('Alpha Services LLC', 52501, 'Alpha invoice');

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor->update(['user_id' => $vendorUser->id]);

        $response = $this->actingAs($vendorUser)->get(route('invoices.index'));

        $response->assertOk();
        $this->assertFalse($response->viewData('page')['props']['canPost']);
    }

    public function test_a_vendor_sees_that_their_invoice_was_posted_but_not_by_whom(): void
    {
        Role::findOrCreate('vendor', 'web');

        [$vendor, , $invoice] = $this->makeVendorInvoice('Alpha Services LLC', 52502, 'Alpha invoice');

        $staff = $this->actingAsAdmin();
        $this->actingAs($staff)->post(route('invoices.posted.store', $invoice->id));

        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');
        $vendor->update(['user_id' => $vendorUser->id]);

        $response = $this->actingAs($vendorUser)->get(route('invoices.index'));

        $response->assertOk();
        $row = collect($response->viewData('page')['props']['invoices']['data'])->firstWhere('id', $invoice->id);

        $this->assertNotNull($row, 'The vendor still sees their own invoice.');
        $this->assertNotNull($row['posted_at']);
        $this->assertNull($row['posted_by'], 'Which staff member posted it stays in the office.');
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

    public function test_an_active_lease_outranks_the_missing_lease_guess(): void
    {
        // WO#43275: an owner lawn-service quote raised against the property
        // rather than a tenancy, so PropertyWare attached no lease however
        // occupied the home is. The leases table says the property is Active,
        // and that is the property's status today, so it wins.
        [, $quoteWo, $quote] = $this->makeVendorInvoice('Breasy Landscaping', 56001, 'Lawn quote invoice');
        $quoteWo->forceFill([
            'propertyware_id' => 993001,
            'building_id' => 771001,
            'lease_id' => null,
            'source' => null,
            'type' => 'Biweekly Lawn Services',
            'category' => 'Lawn service',
            'skip_automated_tasks' => false,
        ])->save();

        Lease::query()->create(['building_id' => 771001, 'status' => 'Active']);

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertFalse($vacantIds->contains($quote->id), 'An actively leased home is not vacant.');

        $occupied = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'occupied']));
        $occupied->assertOk();
        $occupiedIds = collect($occupied->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($occupiedIds->contains($quote->id), 'It belongs under Occupied.');
    }

    public function test_a_non_active_lease_leaves_the_missing_lease_guess_alone(): void
    {
        // Only an active lease overrides the guess. A terminated one means the
        // tenancy has ended, so the row stays vacant as before.
        [, $endedWo, $ended] = $this->makeVendorInvoice('Alpha Services LLC', 56002, 'Ended lease invoice');
        $endedWo->forceFill([
            'propertyware_id' => 993002,
            'building_id' => 771002,
            'lease_id' => null,
            'source' => null,
            'type' => 'Repair',
            'skip_automated_tasks' => false,
        ])->save();

        Lease::query()->create(['building_id' => 771002, 'status' => 'Terminated']);

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($ended->id), 'A terminated lease stays vacant.');
    }

    public function test_a_turnover_stays_vacant_even_with_an_active_lease(): void
    {
        // The job type describes the work, not the property: a turnover is
        // vacant work whatever the leases table currently says.
        [, $turnoverWo, $turnover] = $this->makeVendorInvoice('Beta Services LLC', 56003, 'Turnover invoice');
        $turnoverWo->forceFill([
            'propertyware_id' => 993003,
            'building_id' => 771003,
            'lease_id' => null,
            'type' => 'Turnover',
        ])->save();

        Lease::query()->create(['building_id' => 771003, 'status' => 'Active']);

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($turnover->id), 'A turnover is still vacant work.');
    }

    public function test_a_building_with_no_lease_row_keeps_the_old_behaviour(): void
    {
        // Most buildings have no lease row yet (sync:leases runs nightly and
        // matches on address). Those must behave exactly as before the change.
        [, $noRowWo, $noRow] = $this->makeVendorInvoice('Gamma Services LLC', 56004, 'No lease row invoice');
        $noRowWo->forceFill([
            'propertyware_id' => 993004,
            'building_id' => 771004,
            'lease_id' => null,
            'source' => null,
            'type' => 'Repair',
            'skip_automated_tasks' => false,
        ])->save();

        $admin = $this->actingAsAdmin();

        $vacant = $this->actingAs($admin)->get(route('invoices.index', ['occupancy' => 'vacant']));
        $vacant->assertOk();
        $vacantIds = collect($vacant->viewData('page')['props']['invoices']['data'])->pluck('id');
        $this->assertTrue($vacantIds->contains($noRow->id), 'No lease row means the old guess still applies.');
    }
}
