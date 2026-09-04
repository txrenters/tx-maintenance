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
