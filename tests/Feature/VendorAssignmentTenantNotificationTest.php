<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VendorAssignmentTenantNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate on for the assignment text; owner gate off and a phoneless vendor
        // so the only outbound SMS the job can produce is the tenant one under
        // test. Deterministic "from" number so the send fires.
        config([
            'services.twilio.tenant_assignment_sms' => true,
            'services.twilio.owner_assignment_sms' => false,
            'services.twilio.maintenance_number' => '+12813787957',
        ]);
    }

    private function makeTenant(?string $phone = '5125559999'): Tenants
    {
        return Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'email' => 't'.uniqid().'@example.com',
            'mobile_phone' => $phone,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * A vendor with no email (so the email step is skipped) and no user phone
     * (so no vendor SMS fires), isolating the tenant notification under test.
     */
    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['phone' => null]);

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => null,
            'user_id' => $user->id,
        ]);
    }

    private function openWorkOrder(?Tenants $tenant = null, array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create(array_merge([
            'status' => 'Open',
            'work_order_no' => 6100 + random_int(1, 800),
            'tenant_id' => ($tenant ?? $this->makeTenant())->id,
        ], $attributes));
    }

    /**
     * Run the assignment job synchronously with the heavy PDF/PropertyWare
     * dependencies stubbed out, exactly as it runs on a real vendor assignment.
     */
    private function runAssignment(WorkOrder $workOrder, Vendor $vendor): void
    {
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-'.$vendor->id]);

        $pdf = Mockery::mock(WorkOrderInformationPdf::class);
        $pdf->shouldReceive('render')->andReturn('PDF-BYTES');

        $propertyWare = Mockery::mock(PropertyWareService::class);
        $propertyWare->shouldReceive('uploadWorkOrderPdf')->andReturnFalse();

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))->handle($pdf, $propertyWare);
    }

    public function test_it_texts_the_tenant_when_a_third_party_vendor_is_assigned(): void
    {
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->runAssignment($workOrder, $this->makeVendor());

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]);
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_it_does_not_text_the_tenant_for_thmp(): void
    {
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->runAssignment($workOrder, $this->makeVendor('Texas Home Maintenance Pros'));

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_text_when_the_gate_is_off(): void
    {
        config(['services.twilio.tenant_assignment_sms' => false]);
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->runAssignment($workOrder, $this->makeVendor());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_text_the_tenant_when_vacant(): void
    {
        Queue::fake();

        $workOrder = $this->openWorkOrder(attributes: ['skip_automated_tasks' => true]);
        $this->runAssignment($workOrder, $this->makeVendor());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_text_when_tenant_automation_is_paused(): void
    {
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $workOrder->setAutomationPaused('tenant', true);
        $this->runAssignment($workOrder, $this->makeVendor());

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_text_for_the_owner_vendor_placeholder(): void
    {
        Queue::fake();

        $workOrder = $this->openWorkOrder();
        $this->runAssignment($workOrder, $this->makeVendor('OWNER VENDOR'));

        $this->assertDatabaseCount('work_order_conversations', 0);
        Queue::assertNothingPushed();
    }
}
