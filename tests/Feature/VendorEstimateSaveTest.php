<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Vendor Edit tab's estimate save (VendorNotesController) and the
 * THMP-only estimate calculator that feeds it.
 */
class VendorEstimateSaveTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendorUser(string $vendorName, string $pwId = 'V-1'): array
    {
        Role::findOrCreate('vendor', 'web');

        $user = User::factory()->create();
        $user->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => $pwId,
            'name' => $vendorName,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    private function makeWorkOrder(): WorkOrder
    {
        $serviceStatus = ServiceStatus::query()->create([
            'name' => 'Waiting on Estimate',
            'description' => 'Waiting on Estimate',
        ]);

        return WorkOrder::factory()->create([
            'service_status_id' => $serviceStatus->id,
            'work_order_no' => 4567,
            'description' => 'A/C constantly running and leaking',
        ]);
    }

    public function test_a_saved_estimate_is_stored_on_the_vendor_row(): void
    {
        [$user, $vendor] = $this->makeVendorUser(Vendor::THMP_NAME);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-thmp']);

        $levelBefore = DB::transactionLevel();

        $this->actingAs($user)->post(route('api.vendor_work_order_details.update'), [
            'cost_estimate' => 365.75,
            'time_estimate' => 3,
            'scheduled_end_date' => '2026-10-22',
            'work_order_id' => $workOrder->id,
        ])->assertRedirect();

        // The old controller opened a transaction and never committed it, so
        // the row only looked saved until the connection closed.
        $this->assertSame($levelBefore, DB::transactionLevel(), 'The estimate save must commit its transaction.');

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'cost_estimate' => 365.75,
            'time_estimate' => 3,
            'scheduled_end_date' => '2026-10-22',
        ]);
    }

    public function test_a_saved_estimate_rings_the_coordinator_bell(): void
    {
        [$user, $vendor] = $this->makeVendorUser(Vendor::THMP_NAME);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-thmp']);

        $this->actingAs($user)->post(route('api.vendor_work_order_details.update'), [
            'cost_estimate' => 365.75,
            'time_estimate' => 3,
            'scheduled_end_date' => '2026-10-22',
            'work_order_id' => $workOrder->id,
        ])->assertRedirect();

        $activity = Activity::query()
            ->where('event', 'vendor_estimate_submitted')
            ->where('subject_type', $workOrder->getMorphClass())
            ->where('subject_id', $workOrder->id)
            ->first();

        $this->assertNotNull($activity, 'The estimate save must leave a bell entry on the work order.');
        $this->assertSame(config('activitylog.default_log_name'), $activity->log_name, 'Only the default log reaches the bell.');
        $this->assertSame('Work Order #4567 - Vendor Estimate Submitted', $activity->description);
        $this->assertSame($vendor->id, $activity->properties['vendor_id']);
        $this->assertSame($workOrder->id, $activity->properties['work_order_id']);
        $this->assertSame('Texas Home Maintenance Pros estimated $365.75 (3 hrs).', $activity->properties['message']);
        $this->assertFalse($activity->properties['read']);

        // The assigned coordinator is told; vendors are not re-told; nothing
        // goes to PropertyWare (no PW call exists on this path).
        $this->assertSame('assigned', config('staff_notifications.events.vendor_estimate_submitted.audience'));
        $this->assertFalse((bool) (config('staff_notifications.events.vendor_estimate_submitted.vendors') ?? false));
    }

    public function test_an_estimate_without_hours_reads_naturally_on_the_bell(): void
    {
        [$user, $vendor] = $this->makeVendorUser('Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-acme']);

        $this->actingAs($user)->post(route('api.vendor_work_order_details.update'), [
            'cost_estimate' => 1250,
            'scheduled_end_date' => '2026-10-22',
            'work_order_id' => $workOrder->id,
        ])->assertRedirect();

        $this->assertSame('Acme Plumbing estimated $1,250.00.', Activity::query()
            ->where('event', 'vendor_estimate_submitted')
            ->latest('id')
            ->value('properties')['message']);
    }

    public function test_the_calculator_is_offered_to_the_thmp_login_with_the_rates(): void
    {
        [$user, $vendor] = $this->makeVendorUser(Vendor::THMP_NAME);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-thmp']);

        $this->actingAs($user)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.vendor_is_thmp', true)
                ->where('vendorEstimateRates.labor_rate', 85)
                ->where('vendorEstimateRates.material_tax_rate', 0.0825)
            );
    }

    public function test_the_calculator_rates_come_from_config(): void
    {
        config([
            'services.vendor_estimate.labor_rate' => 95,
            'services.vendor_estimate.material_tax_rate' => 0.07,
        ]);

        [$user, $vendor] = $this->makeVendorUser(Vendor::THMP_NAME);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-thmp']);

        $this->actingAs($user)
            ->get(route('work_orders.details', $workOrder))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vendorEstimateRates.labor_rate', 95)
                ->where('vendorEstimateRates.material_tax_rate', 0.07)
            );
    }

    public function test_the_calculator_is_not_offered_to_other_vendors(): void
    {
        [$user, $vendor] = $this->makeVendorUser('Acme Plumbing');
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-acme']);

        $this->actingAs($user)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.vendor_is_thmp', false)
                ->where('vendorEstimateRates', null)
            );
    }

    /**
     * A coordinator login the THMP crew works from, with no vendor row of its
     * own: listed by email, it gets the calculator too (Earl, 2026-10-20).
     */
    private function makeListedStaffUser(string $email = 'xservice@txhomemp.com'): User
    {
        Role::findOrCreate('woc', 'web');

        $user = User::factory()->create(['email' => $email]);
        $user->assignRole('woc');

        return $user;
    }

    public function test_a_listed_staff_login_is_offered_the_calculator(): void
    {
        $user = $this->makeListedStaffUser();
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($user)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.vendor_is_thmp', true)
                ->where('vendorEstimateRates.labor_rate', 85)
            );
    }

    public function test_the_listed_logins_come_from_config(): void
    {
        config(['services.vendor_estimate.logins' => 'crew@example.test, Other@Example.test']);

        $listed = $this->makeListedStaffUser('other@example.test');
        $unlisted = $this->makeListedStaffUser('xservice@txhomemp.com');
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($listed)
            ->get(route('work_orders.details', $workOrder))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.vendor_is_thmp', true));

        $this->actingAs($unlisted)
            ->get(route('work_orders.details', $workOrder))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.vendor_is_thmp', false));
    }

    public function test_a_plain_coordinator_is_not_offered_the_calculator(): void
    {
        $user = $this->makeListedStaffUser('someone@texasrenters.com');
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($user)
            ->get(route('work_orders.details', $workOrder))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.vendor_is_thmp', false)
                ->where('vendorEstimateRates', null)
            );
    }

    public function test_a_listed_staff_login_saves_the_estimate_on_thmps_vendor_row(): void
    {
        $user = $this->makeListedStaffUser();
        [, $thmp] = $this->makeVendorUser(Vendor::THMP_NAME, Vendor::THMP_PROPERTYWARE_ID);
        $workOrder = $this->makeWorkOrder();
        $workOrder->vendors()->attach($thmp->id, ['access_token' => 'tok-thmp']);

        $this->actingAs($user)->post(route('api.vendor_work_order_details.update'), [
            'cost_estimate' => 365.75,
            'time_estimate' => 3,
            'scheduled_end_date' => '2026-10-22',
            'work_order_id' => $workOrder->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_vendors', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $thmp->id,
            'cost_estimate' => 365.75,
            'time_estimate' => 3,
        ]);

        $this->assertSame('Texas Home Maintenance Pros estimated $365.75 (3 hrs).', Activity::query()
            ->where('event', 'vendor_estimate_submitted')
            ->latest('id')
            ->value('properties')['message']);
    }

    public function test_a_staff_login_without_a_vendor_cannot_save_an_estimate(): void
    {
        $user = $this->makeListedStaffUser('someone@texasrenters.com');
        $workOrder = $this->makeWorkOrder();

        $this->actingAs($user)->post(route('api.vendor_work_order_details.update'), [
            'cost_estimate' => 100,
            'work_order_id' => $workOrder->id,
        ])->assertRedirect()->assertSessionHasErrors('vendor');

        $this->assertDatabaseMissing('activity_log', ['event' => 'vendor_estimate_submitted']);
    }
}
