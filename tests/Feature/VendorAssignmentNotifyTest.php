<?php

namespace Tests\Feature;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The manual "Send assignment info" button on the vendor conversation tab:
 * re-arms the one-shot claim and queues the same assignment email + text the
 * automation sends when a vendor is attached through the app.
 */
class VendorAssignmentNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendor(array $overrides = [], ?string $userPhone = '3255550101'): Vendor
    {
        $user = User::factory()->create($userPhone ? ['phone' => $userPhone] : ['phone' => null]);

        return Vendor::query()->create(array_merge([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1, 100000),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'vendor@example.com',
            'user_id' => $user->id,
        ], $overrides));
    }

    private function notify(WorkOrder $workOrder, Vendor $vendor)
    {
        return $this->actingAs(User::factory()->create())
            ->postJson(route('work_orders.vendor.notify_assignment', [$workOrder, $vendor]));
    }

    private function pivot(WorkOrder $workOrder, Vendor $vendor): ?object
    {
        return DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->first();
    }

    public function test_it_rearms_the_claim_and_queues_the_assignment_job(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create();
        // A PropertyWare-imported assignment: already stamped as notified (it
        // never was) and holding no portal token.
        $workOrder->vendors()->attach($vendor->id, ['information_sent_at' => now()]);

        $this->notify($workOrder, $vendor)
            ->assertOk()
            ->assertJson(['queued' => true, 'email' => true, 'text' => true]);

        Bus::assertDispatched(SendVendorWorkOrderInformation::class, fn ($job) => $job->workOrderId === $workOrder->id
            && $job->vendorId === $vendor->id);

        $pivot = $this->pivot($workOrder, $vendor);
        $this->assertNull($pivot->information_sent_at);
        $this->assertNotNull($pivot->access_token);
    }

    public function test_an_existing_portal_token_is_kept(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-keep']);

        $this->notify($workOrder, $vendor)->assertOk();

        $this->assertSame('tok-keep', $this->pivot($workOrder, $vendor)->access_token);
    }

    public function test_it_refuses_a_vendor_who_is_not_assigned(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create();

        $this->notify($workOrder, $vendor)->assertStatus(422);

        Bus::assertNotDispatched(SendVendorWorkOrderInformation::class);
    }

    public function test_it_refuses_while_vendor_automation_is_paused(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);
        $workOrder->setAutomationPaused('vendor', true);

        $this->notify($workOrder, $vendor)->assertStatus(422);

        Bus::assertNotDispatched(SendVendorWorkOrderInformation::class);
    }

    public function test_it_refuses_a_vendor_with_no_contact_info(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor(['email' => null], userPhone: null);
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        $this->notify($workOrder, $vendor)->assertStatus(422);

        Bus::assertNotDispatched(SendVendorWorkOrderInformation::class);
    }

    public function test_it_refuses_the_owner_vendor_placeholder(): void
    {
        Bus::fake();

        // Even with a phone on file (its user row used to be shared with every
        // e-mail-less vendor, so it showed a stranger's number) there is no
        // vendor behind "OWNER VENDOR" to send assignment info to.
        $vendor = $this->makeVendor(['name' => 'OWNER VENDOR', 'email' => null]);
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-ov']);

        $this->notify($workOrder, $vendor)
            ->assertStatus(422)
            ->assertJson(['error' => 'OWNER VENDOR is a placeholder for the owner handling the repair themselves. There is no vendor to notify.']);

        Bus::assertNotDispatched(SendVendorWorkOrderInformation::class);
    }

    public function test_guests_cannot_trigger_it(): void
    {
        Bus::fake();

        $vendor = $this->makeVendor();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);

        $this->postJson(route('work_orders.vendor.notify_assignment', [$workOrder, $vendor]))
            ->assertUnauthorized();

        Bus::assertNotDispatched(SendVendorWorkOrderInformation::class);
    }
}
