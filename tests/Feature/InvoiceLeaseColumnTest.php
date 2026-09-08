<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Lease column on the invoice list: it shows the property's current
 * PropertyWare lease status, so accounting can see whether a home is still
 * tenanted without opening PropertyWare.
 */
class InvoiceLeaseColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoiceForBuilding(?int $buildingId, int $workOrderNo = 51001): Invoice
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.$workOrderNo,
            'name' => 'Lone Star Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => $workOrderNo,
            'status' => 'Open',
            'building_id' => $buildingId,
        ]);

        return Invoice::query()->create([
            'title' => 'Invoice '.$workOrderNo,
            'filename' => 'invoices/'.$workOrderNo.'.pdf',
            'filetype' => 'application/pdf',
            'amount' => 180.00,
            'status' => 'approved',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowFor(Invoice $invoice): ?array
    {
        $response = $this->actingAs($this->actingAsAdmin())->get(route('invoices.index'));
        $response->assertOk();

        return collect($response->viewData('page')['props']['invoices']['data'])
            ->firstWhere('id', $invoice->id);
    }

    public function test_it_shows_the_lease_status_for_the_invoices_property(): void
    {
        $invoice = $this->makeInvoiceForBuilding(778001);

        Lease::query()->create([
            'building_id' => 778001,
            'status' => 'Active',
            'address' => '6341 Del Monte Dr',
        ]);

        $this->assertSame('Active', $this->rowFor($invoice)['lease_status']);
    }

    public function test_it_shows_a_status_that_is_not_active(): void
    {
        $invoice = $this->makeInvoiceForBuilding(778002, 51002);

        Lease::query()->create([
            'building_id' => 778002,
            'status' => 'Notice Given',
        ]);

        $this->assertSame('Notice Given', $this->rowFor($invoice)['lease_status']);
    }

    public function test_a_property_with_no_lease_on_file_reports_null(): void
    {
        $invoice = $this->makeInvoiceForBuilding(778004, 51004);

        $row = $this->rowFor($invoice);

        $this->assertArrayHasKey('lease_status', $row, 'The key must always be present so the column can render a dash.');
        $this->assertNull($row['lease_status']);
    }

    public function test_a_work_order_with_no_building_does_not_break_the_page(): void
    {
        $invoice = $this->makeInvoiceForBuilding(null, 51005);

        // A lease elsewhere must not leak onto a building-less row.
        Lease::query()->create([
            'building_id' => 778005,
            'status' => 'Active',
        ]);

        $this->assertNull($this->rowFor($invoice)['lease_status']);
    }

    public function test_the_lease_of_one_property_does_not_show_on_another(): void
    {
        $invoice = $this->makeInvoiceForBuilding(778006, 51006);

        Lease::query()->create([
            'building_id' => 778007,
            'status' => 'Active',
        ]);

        $this->assertNull($this->rowFor($invoice)['lease_status']);
    }

    public function test_the_lease_lookup_does_not_grow_with_the_number_of_rows(): void
    {
        foreach ([51010, 51011, 51012, 51013] as $index => $workOrderNo) {
            $buildingId = 779000 + $index;
            $this->makeInvoiceForBuilding($buildingId, $workOrderNo);
            Lease::query()->create([
                'building_id' => $buildingId,
                'status' => 'Active',
            ]);
        }

        $admin = $this->actingAsAdmin();

        $leaseQueries = 0;
        DB::listen(function ($query) use (&$leaseQueries) {
            if (str_contains($query->sql, 'leases')) {
                $leaseQueries++;
            }
        });

        $this->actingAs($admin)->get(route('invoices.index'))->assertOk();

        $this->assertSame(1, $leaseQueries, 'Lease statuses must be resolved in a single query, not one per invoice.');
    }
}
