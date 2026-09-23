<?php

namespace Tests\Feature;

use App\Jobs\NotifyOperationAccountingOfTurnoverInvoice;
use App\Models\Invoice;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An invoice uploaded against the wrong work order can be moved to the right
 * one from the work order's Invoices tab. Office only; the row keeps its
 * vendor, amount, status and file, and PropertyWare gets a copy on the new
 * work order.
 */
class InvoiceTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeWorkOrder(array $overrides = []): WorkOrder
    {
        $status = ServiceStatus::query()->firstOrCreate(['name' => 'New'], ['description' => 'New']);

        return WorkOrder::factory()->create(array_merge(['service_status_id' => $status->id], $overrides));
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

    private function makeInvoice(WorkOrder $workOrder, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
            'title' => 'Fence invoice',
            'invoice_number' => 'INV-77',
            'amount' => 250,
            'status' => 'approved',
            'filename' => 'invoices/fence.pdf',
            'filetype' => 'application/pdf',
        ], $overrides));
    }

    /**
     * The PropertyWare copy is a network call; stand it in and let a test
     * pin down which work order it was asked to attach the document to.
     */
    private function fakePropertyWare(bool $succeeds = true): Mockery\MockInterface
    {
        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('uploadVendorInvoice')->andReturn($succeeds)->byDefault();
        $this->app->instance(PropertyWareService::class, $mock);

        return $mock;
    }

    public function test_the_office_can_move_an_invoice_to_another_work_order(): void
    {
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder(['work_order_no' => 43552]);
        $to = $this->makeWorkOrder(['work_order_no' => 43560]);
        $invoice = $this->makeInvoice($from);
        $woc = User::factory()->create()->assignRole('woc');

        $this->actingAs($woc)
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Invoice moved to WO #43560.')
            ->assertRedirect();

        $this->assertSame($to->id, $invoice->fresh()->work_order_id);
    }

    public function test_moving_keeps_everything_but_the_work_order(): void
    {
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder();
        $vendor = $this->makeVendor();
        $invoice = $this->makeInvoice($from, [
            'vendor_id' => $vendor->id,
            'status' => 'decline',
            'posted_at' => now(),
        ]);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);

        $moved = $invoice->fresh();
        $this->assertSame($vendor->id, $moved->vendor_id);
        $this->assertSame('decline', $moved->status);
        $this->assertSame('INV-77', $moved->invoice_number);
        $this->assertSame('invoices/fence.pdf', $moved->filename);
        $this->assertNotNull($moved->posted_at);
    }

    public function test_the_moved_invoice_leaves_the_old_work_order_and_shows_on_the_new_one(): void
    {
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);

        $this->assertCount(0, $this->actingAs($admin)->getJson(route('api.invoices.index', $from->id))->json('invoices'));
        $this->assertCount(1, $this->actingAs($admin)->getJson(route('api.invoices.index', $to->id))->json('invoices'));
    }

    public function test_propertyware_gets_a_copy_on_the_new_work_order(): void
    {
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->fakePropertyWare()
            ->shouldReceive('uploadVendorInvoice')
            ->once()
            ->withArgs(fn ($workOrderId, $uploaded) => $workOrderId === $to->id
                && $uploaded->id === $invoice->id
                && $uploaded->work_order_id === $to->id)
            ->andReturn(true);

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);
    }

    public function test_a_failed_propertyware_copy_still_moves_the_invoice_but_warns(): void
    {
        $this->fakePropertyWare(succeeds: false);
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder(['work_order_no' => 43560]);
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id])
            ->assertSessionHas('success')
            ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'PropertyWare') && str_contains($warning, '43560'));

        $this->assertSame($to->id, $invoice->fresh()->work_order_id);
    }

    public function test_the_move_is_written_to_the_activity_log(): void
    {
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder(['work_order_no' => 43552]);
        $to = $this->makeWorkOrder(['work_order_no' => 43560]);
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'invoice',
            'description' => 'transferred',
            'subject_id' => $invoice->id,
            'causer_id' => $admin->id,
        ]);

        $properties = Activity::query()
            ->where('description', 'transferred')
            ->latest('id')
            ->first()
            ->properties;

        $this->assertSame(43552, (int) $properties['from_work_order_no']);
        $this->assertSame(43560, (int) $properties['to_work_order_no']);
    }

    public function test_landing_on_a_turnover_work_order_tells_operation_accounting(): void
    {
        Queue::fake();
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder(['type' => 'Standard']);
        $to = $this->makeWorkOrder(['type' => 'Turnover']);
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);

        Queue::assertPushed(NotifyOperationAccountingOfTurnoverInvoice::class, fn ($job) => $job->invoiceId === $invoice->id);
    }

    public function test_landing_on_an_ordinary_work_order_does_not_email_operation_accounting(): void
    {
        Queue::fake();
        $this->fakePropertyWare();
        $from = $this->makeWorkOrder(['type' => 'Turnover']);
        $to = $this->makeWorkOrder(['type' => 'Standard']);
        $invoice = $this->makeInvoice($from);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id]);

        Queue::assertNotPushed(NotifyOperationAccountingOfTurnoverInvoice::class);
    }

    public function test_moving_to_the_same_work_order_is_refused(): void
    {
        $this->fakePropertyWare()->shouldNotReceive('uploadVendorInvoice');
        $workOrder = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($workOrder);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $workOrder->id])
            ->assertSessionHasErrors('work_order_id');

        $this->assertSame($workOrder->id, $invoice->fresh()->work_order_id);
    }

    public function test_an_unknown_target_is_refused(): void
    {
        $this->fakePropertyWare()->shouldNotReceive('uploadVendorInvoice');
        $workOrder = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($workOrder);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => 999999])
            ->assertSessionHasErrors('work_order_id');

        $this->assertSame($workOrder->id, $invoice->fresh()->work_order_id);
    }

    public function test_a_vendor_cannot_move_an_invoice(): void
    {
        $this->fakePropertyWare()->shouldNotReceive('uploadVendorInvoice');
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $vendor = $this->makeVendor($vendorUser);
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($from, ['vendor_id' => $vendor->id]);

        $this->actingAs($vendorUser)
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id])
            ->assertForbidden();

        $this->assertSame($from->id, $invoice->fresh()->work_order_id);
    }

    public function test_an_archived_invoice_cannot_be_moved(): void
    {
        $this->fakePropertyWare()->shouldNotReceive('uploadVendorInvoice');
        $from = $this->makeWorkOrder();
        $to = $this->makeWorkOrder();
        $invoice = $this->makeInvoice($from, ['archived_at' => now()]);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('api.invoices.transfer', $invoice->id), ['work_order_id' => $to->id])
            ->assertNotFound();
    }
}
