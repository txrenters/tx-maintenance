<?php

namespace Tests\Feature;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Crystal Creek Air endpoints, and every work order action that used to
 * reach for PropertyWare, on a work order PropertyWare has never heard of.
 */
class CrystalCreekControllerTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $thmp;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);

        $this->thmp = Vendor::query()->create([
            'propertyware_id' => Vendor::THMP_PROPERTYWARE_ID,
            'name' => Vendor::THMP_NAME,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        // Nothing in these tests may reach PropertyWare or Jobber.
        Http::fake();
    }

    private function staff(string $role = 'woc'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pat Customer',
            'phone' => '(512) 555-0100',
            'email' => 'pat@example.com',
            'street' => '1234 Oak St',
            'city' => 'Cypress',
            'state' => 'TX',
            'postal_code' => '77429',
            'category' => 'HVAC',
            'type' => 'Repair',
            'description' => 'AC not cooling',
        ], $overrides);
    }

    private function crystalCreekWorkOrder(): WorkOrder
    {
        $customer = OutsideCustomer::factory()->create();

        return WorkOrder::factory()->create([
            'work_order_no' => 7000001,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'outside_customer_id' => $customer->id,
            'propertyware_id' => null,
            'building_id' => null,
            'service_status_id' => ServiceStatus::query()->where('name', 'New')->value('id'),
        ]);
    }

    public function test_staff_create_a_work_order_from_the_dialog(): void
    {
        Queue::fake();

        $this->actingAs($this->staff())
            ->post(route('work_orders.crystal_creek.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Work order #1 created') && str_contains($message, 'Pat Customer'));

        $workOrder = WorkOrder::query()->crystalCreek()->first();
        $this->assertNotNull($workOrder);
        $this->assertSame($this->thmp->id, $workOrder->vendors()->first()?->id);
        Queue::assertPushed(CreateJobberJobForWorkOrder::class, 1);
        Http::assertNothingSent();
    }

    public function test_the_form_needs_a_phone_or_an_email_and_a_full_address(): void
    {
        $this->actingAs($this->staff())
            ->from(route('work_orders.crystal_creek'))
            ->post(route('work_orders.crystal_creek.store'), $this->payload([
                'phone' => '',
                'email' => '',
                'postal_code' => '7742',
                'state' => 'Texas',
            ]))
            ->assertSessionHasErrors(['phone', 'email', 'postal_code', 'state']);

        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_a_vendor_login_may_not_create_or_view_the_board(): void
    {
        $vendor = $this->staff('vendor');

        $this->actingAs($vendor)->post(route('work_orders.crystal_creek.store'), $this->payload())->assertForbidden();
        $this->actingAs($vendor)->get(route('work_orders.crystal_creek'))->assertForbidden();
    }

    public function test_the_board_page_renders_for_staff(): void
    {
        $this->actingAs($this->staff('admin'))
            ->get(route('work_orders.crystal_creek'))
            ->assertOk();
    }

    public function test_changing_vendors_never_calls_propertyware_or_mails_anyone_and_creates_the_jobber_job(): void
    {
        Queue::fake();
        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('changeWorkOrderVendors');
        });
        $workOrder = $this->crystalCreekWorkOrder();

        $this->actingAs($this->staff('admin'))
            ->put(route('work_orders.vendor.change', $workOrder), ['vendor_ids' => [$this->thmp->id]])
            ->assertSessionHas('success');

        $pivot = DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->where('vendor_id', $this->thmp->id)->first();
        $this->assertNotNull($pivot);
        $this->assertNotNull($pivot->schedule_followup_sent_at);
        Queue::assertPushed(CreateJobberJobForWorkOrder::class, 1);
        Queue::assertNotPushed(SendVendorWorkOrderInformation::class);
        Queue::assertNotPushed(SendOwnerVendorAssignmentEmail::class);
    }

    public function test_saving_the_details_reports_success_without_propertyware(): void
    {
        $workOrder = $this->crystalCreekWorkOrder();

        $this->actingAs($this->staff('admin'))
            ->put(route('work_orders.update', $workOrder), [
                'work_order_no' => $workOrder->work_order_no,
                'description' => 'AC not cooling, filter clogged',
                'category' => 'HVAC',
                'type' => 'Repair',
                'priority' => 'Medium',
            ])
            ->assertSessionHas('success', 'Work order updated.')
            ->assertSessionMissing('error');

        $this->assertSame('AC not cooling, filter clogged', $workOrder->fresh()->description);
        Http::assertNothingSent();
    }

    public function test_closing_and_reopening_stay_local(): void
    {
        $workOrder = $this->crystalCreekWorkOrder();
        $staff = $this->staff('admin');

        $this->actingAs($staff)->put(route('work_orders.close', $workOrder));
        $this->assertSame('Closed', $workOrder->fresh()->status);
        $this->assertNotNull($workOrder->fresh()->completed_date);

        $this->actingAs($staff)
            ->put(route('work_orders.open', $workOrder))
            ->assertRedirect(route('work_orders.crystal_creek'));
        $this->assertSame('Open', $workOrder->fresh()->status);

        Http::assertNothingSent();
    }

    public function test_a_note_saves_with_no_propertyware_warning(): void
    {
        $workOrder = $this->crystalCreekWorkOrder();

        $this->actingAs($this->staff('admin'))
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Diagnosis',
                'body' => 'Capacitor failed.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect()
            ->assertSessionMissing('warning');

        $this->assertSame(1, WorkOrderNotes::query()->where('work_order_id', $workOrder->id)->count());
        Http::assertNothingSent();
    }

    public function test_intake_notifications_are_refused(): void
    {
        $workOrder = $this->crystalCreekWorkOrder();

        $this->actingAs($this->staff('admin'))
            ->postJson(route('work_orders.notify_intake', [$workOrder, 'tenant']))
            ->assertStatus(422);
    }

    public function test_the_modal_data_carries_the_customer(): void
    {
        $workOrder = $this->crystalCreekWorkOrder();
        $workOrder->vendors()->attach($this->thmp->id);
        $workOrder->update(['jobber_web_uri' => 'https://secure.getjobber.com/work_orders/1']);

        $this->actingAs($this->staff())
            ->getJson(route('work_orders.data', $workOrder))
            ->assertOk()
            ->assertJsonPath('source', WorkOrder::CRYSTAL_CREEK_SOURCE)
            ->assertJsonPath('outside_customer.id', $workOrder->outside_customer_id)
            ->assertJsonPath('jobber_web_uri', 'https://secure.getjobber.com/work_orders/1');
    }
}
