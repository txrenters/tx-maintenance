<?php

namespace Tests\Feature;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Notifications\NewWorkOrderAssignNotification;
use App\Services\PropertyWareService;
use App\Services\VendorAssignmentService;
use App\Services\WorkOrderEmailSender;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A vendor can name extra addresses (an office inbox, a partner) that are
 * copied on every email the app sends them, because some vendors cannot read
 * the mailbox PropertyWare holds for them.
 */
class VendorCcEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::forget('microsoft.graph.token');
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID', 'internetMessageId' => '<x@texasrenters.com>', 'conversationId' => 'CID',
            ]),
        ]);
    }

    private function makeVendor(array $overrides = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Fantastic Floors, Inc',
            'email' => 'kim@example.com',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ], $overrides));
    }

    private function stubPdfAndPropertyWare(): void
    {
        $pdf = Mockery::mock(WorkOrderInformationPdf::class);
        $pdf->shouldReceive('render')->andReturn('%PDF-fake');
        $this->app->instance(WorkOrderInformationPdf::class, $pdf);

        $pw = Mockery::mock(PropertyWareService::class);
        $pw->shouldReceive('uploadWorkOrderPdf')->andReturn(false);
        $this->app->instance(PropertyWareService::class, $pw);
    }

    /**
     * The cc list Graph was asked to send, from the stored outbound message.
     *
     * @return list<string>
     */
    private function graphCcRecipients(): array
    {
        $cc = [];
        Http::assertSent(function ($request) use (&$cc) {
            if (str_ends_with($request->url(), '/messages')) {
                $cc = array_map(fn ($r) => $r['emailAddress']['address'], $request['ccRecipients'] ?? []);

                return true;
            }

            return false;
        });

        return $cc;
    }

    public function test_the_request_email_copies_the_vendors_extra_addresses(): void
    {
        $this->stubPdfAndPropertyWare();

        $vendor = $this->makeVendor(['cc_emails' => ['sales@example.com']]);
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $cc = $this->graphCcRecipients();
        $this->assertContains('sales@example.com', $cc);
        foreach (WorkOrderEmailSender::DEFAULT_CC as $office) {
            $this->assertContains($office, $cc);
        }

        $stored = EmailMessage::query()->where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertSame('kim@example.com', $stored->to_email);
        $this->assertContains('sales@example.com', $stored->cc);
    }

    public function test_a_vendor_without_extra_addresses_keeps_the_office_copies_only(): void
    {
        $this->stubPdfAndPropertyWare();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4322]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertEqualsCanonicalizing(WorkOrderEmailSender::DEFAULT_CC, $this->graphCcRecipients());
    }

    public function test_an_extra_address_equal_to_the_vendors_own_is_not_copied_twice(): void
    {
        $this->stubPdfAndPropertyWare();

        $vendor = $this->makeVendor(['cc_emails' => ['KIM@example.com', 'sales@example.com', 'sales@example.com']]);
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4323]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $cc = $this->graphCcRecipients();
        $this->assertNotContains('KIM@example.com', $cc);
        $this->assertNotContains('kim@example.com', $cc);
        $this->assertSame(1, count(array_filter($cc, fn ($a) => $a === 'sales@example.com')));
    }

    public function test_the_auto_assignment_notification_copies_the_extra_addresses(): void
    {
        Notification::fake();

        $pw = Mockery::mock(PropertyWareService::class);
        $pw->shouldReceive('changeWorkOrderVendors')->andReturn(true);
        $this->app->instance(PropertyWareService::class, $pw);

        $vendor = $this->makeVendor(['cc_emails' => ['sales@example.com']]);
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4324]);

        app(VendorAssignmentService::class)->autoAssign($workOrder, $vendor, 'test');

        Notification::assertSentTo($vendor, NewWorkOrderAssignNotification::class, function ($notification, $channels, $notifiable) {
            $cc = array_map(fn ($entry) => $entry[0], $notification->toMail($notifiable)->cc);

            return in_array('sales@example.com', $cc, true);
        });
    }

    public function test_staff_can_save_the_extra_addresses_as_a_comma_separated_list(): void
    {
        $vendor = $this->makeVendor();
        $staff = $this->staffWhoCanEditVendors();

        $this->actingAs($staff)->put(route('vendors.update', $vendor), [
            'name' => $vendor->name,
            'email' => 'kim@example.com',
            'cc_emails' => ' sales@example.com, Office@Example.com ,, sales@example.com ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['sales@example.com', 'office@example.com'], $vendor->fresh()->cc_emails);
    }

    public function test_a_blank_extra_address_list_clears_it(): void
    {
        $vendor = $this->makeVendor(['cc_emails' => ['sales@example.com']]);
        $staff = $this->staffWhoCanEditVendors();

        $this->actingAs($staff)->put(route('vendors.update', $vendor), [
            'name' => $vendor->name,
            'email' => 'kim@example.com',
            'cc_emails' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($vendor->fresh()->cc_emails);
    }

    public function test_a_malformed_extra_address_is_refused(): void
    {
        $vendor = $this->makeVendor();
        $staff = $this->staffWhoCanEditVendors();

        $this->actingAs($staff)->from(route('vendors.index'))->put(route('vendors.update', $vendor), [
            'name' => $vendor->name,
            'email' => 'kim@example.com',
            'cc_emails' => 'sales@example.com, not-an-email',
        ])->assertRedirect()->assertSessionHasErrors('cc_emails');

        $this->assertNull($vendor->fresh()->cc_emails);
    }

    public function test_the_vendor_list_shows_the_extra_addresses(): void
    {
        $vendor = $this->makeVendor(['cc_emails' => ['sales@example.com']]);
        $staff = $this->staffWhoCanEditVendors();

        $this->actingAs($staff)->get(route('vendors.index'))
            ->assertOk()
            ->assertSee('sales@example.com');
    }

    /**
     * Admins pass every gate (AppServiceProvider's Gate::before).
     */
    private function staffWhoCanEditVendors(): User
    {
        Role::findOrCreate('admin');
        $staff = User::factory()->create();
        $staff->assignRole('admin');

        return $staff;
    }
}
