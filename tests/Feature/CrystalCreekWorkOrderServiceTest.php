<?php

namespace Tests\Feature;

use App\Jobs\CreateJobberJobForWorkOrder;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\CrystalCreekWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrystalCreekWorkOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $thmp;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);

        $this->thmp = Vendor::query()->create([
            'propertyware_id' => Vendor::THMP_PROPERTYWARE_ID,
            'name' => Vendor::THMP_NAME,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function callerData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pat Customer',
            'phone' => '(512) 555-0100',
            'email' => 'pat@example.com',
            'street' => '1234 Oak St',
            'city' => 'Cypress',
            'state' => 'tx',
            'postal_code' => '77429',
            'category' => 'HVAC',
            'type' => 'Repair',
            'description' => "AC not   cooling\nsince Monday",
        ], $overrides);
    }

    private function service(): CrystalCreekWorkOrderService
    {
        return app(CrystalCreekWorkOrderService::class);
    }

    public function test_it_creates_the_work_order_with_the_first_number_of_the_series(): void
    {
        Queue::fake();
        // PropertyWare numbers already in the table must not move the series.
        WorkOrder::factory()->create(['work_order_no' => 44321]);

        $workOrder = $this->service()->create($this->callerData(), User::factory()->create());

        $this->assertSame(7000001, (int) $workOrder->work_order_no);
        $this->assertSame(WorkOrder::CRYSTAL_CREEK_SOURCE, $workOrder->source);
        $this->assertTrue($workOrder->isCrystalCreek());
        $this->assertNull($workOrder->propertyware_id);
        $this->assertNull($workOrder->building_id);
        $this->assertSame('Open', $workOrder->status);
        $this->assertSame('New', $workOrder->service_status->name);
        $this->assertSame('1234 Oak St, Cypress, TX 77429', $workOrder->location);
        $this->assertSame('Pat Customer', $workOrder->service_request_contact_name);
        $this->assertSame('+15125550100', $workOrder->service_request_contact_phone);
        $this->assertSame('pat@example.com', $workOrder->service_request_contact_email);
        $this->assertSame("AC not   cooling\nsince Monday", $workOrder->description);
    }

    public function test_numbers_follow_one_another(): void
    {
        Queue::fake();

        $first = $this->service()->create($this->callerData(), null);
        $second = $this->service()->create($this->callerData(['phone' => '(512) 555-0199', 'email' => 'other@example.com', 'street' => '9 Elm Ave']), null);

        $this->assertSame(7000001, (int) $first->work_order_no);
        $this->assertSame(7000002, (int) $second->work_order_no);
    }

    public function test_thmp_is_attached_without_a_portal_token_and_with_the_followup_opt_out(): void
    {
        Queue::fake();

        $workOrder = $this->service()->create($this->callerData(), null);

        $pivot = DB::table('work_order_vendors')->where('work_order_id', $workOrder->id)->first();

        $this->assertNotNull($pivot);
        $this->assertSame($this->thmp->id, (int) $pivot->vendor_id);
        $this->assertNull($pivot->access_token);
        $this->assertNotNull($pivot->schedule_followup_sent_at);
    }

    public function test_the_jobber_job_is_queued_once(): void
    {
        Queue::fake();

        $workOrder = $this->service()->create($this->callerData(), null);

        Queue::assertPushed(CreateJobberJobForWorkOrder::class, fn ($job) => $job->workOrderId === $workOrder->id);
        Queue::assertPushed(CreateJobberJobForWorkOrder::class, 1);
    }

    public function test_a_returning_caller_is_matched_by_phone_and_not_duplicated(): void
    {
        Queue::fake();

        $first = $this->service()->create($this->callerData(), null);
        $first->outsideCustomer->update(['jobber_property_gid' => 'GID-PROP-KEPT']);

        $second = $this->service()->create($this->callerData([
            'phone' => '512-555-0100',
            'email' => null,
            'street' => '1234 Oak Street',
        ]), null);

        $this->assertSame(1, OutsideCustomer::query()->count());
        $this->assertSame($first->outside_customer_id, $second->outside_customer_id);
        $this->assertSame('GID-PROP-KEPT', $second->outsideCustomer->jobber_property_gid);
        // The matched customer keeps what it had; only blanks are filled.
        $this->assertSame('1234 Oak St', $second->outsideCustomer->street);
    }

    public function test_a_returning_caller_is_matched_by_email_then_by_address(): void
    {
        Queue::fake();

        $this->service()->create($this->callerData(), null);

        $byEmail = $this->service()->create($this->callerData(['phone' => null, 'email' => 'PAT@example.com']), null);
        $byAddress = $this->service()->create($this->callerData(['phone' => null, 'email' => null, 'street' => '1234 OAK ST']), null);

        $this->assertSame(1, OutsideCustomer::query()->count());
        $this->assertSame($byEmail->outside_customer_id, $byAddress->outside_customer_id);
    }

    public function test_a_different_caller_gets_their_own_customer(): void
    {
        Queue::fake();

        $this->service()->create($this->callerData(), null);
        $this->service()->create($this->callerData([
            'name' => 'Sam Other',
            'phone' => '(512) 555-0222',
            'email' => 'sam@example.com',
            'street' => '55 Pine Rd',
        ]), null);

        $this->assertSame(2, OutsideCustomer::query()->count());
    }

    public function test_a_missing_thmp_vendor_leaves_the_work_order_without_a_vendor_but_still_creates_it(): void
    {
        Queue::fake();
        $this->thmp->delete();

        $workOrder = $this->service()->create($this->callerData(), null);

        $this->assertSame(0, $workOrder->vendors()->count());
        $this->assertSame(7000001, (int) $workOrder->work_order_no);
    }
}
