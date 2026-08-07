<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Open in Jobber" button only belongs on a work order the in-house crew
 * (THMP) is assigned to — that is the only case a Jobber job exists — and only
 * staff plus THMP's own vendor account may follow it. The URL is withheld from
 * the payload rather than merely hidden in the template, so a third-party
 * vendor cannot read it out of the browser's devtools either.
 */
class JobberLinkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const JOBBER_URI = 'https://secure.getjobber.com/work_orders/9001';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeWorkOrder(): WorkOrder
    {
        return WorkOrder::factory()->create([
            'work_order_no' => 9001,
            'status' => 'Open',
            'jobber_web_uri' => self::JOBBER_URI,
            'jobber_job_gid' => 'gid://Jobber/Job/9001',
        ]);
    }

    private function makeVendorUser(string $name, ?WorkOrder $workOrder = null): User
    {
        $vendorUser = User::factory()->create();
        $vendorUser->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'is_active' => true,
            'user_id' => $vendorUser->id,
        ]);

        $workOrder?->vendors()->attach($vendor->id);

        return $vendorUser;
    }

    private function makeStaff(string $role = 'woc'): User
    {
        $staff = User::factory()->create();
        $staff->assignRole($role);

        return $staff;
    }

    private function detailsPage(User $user, WorkOrder $workOrder)
    {
        return $this->actingAs($user)->get(route('work_orders.details', $workOrder->id), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);
    }

    public function test_staff_receive_the_jobber_link_when_thmp_is_assigned(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeVendorUser(Vendor::THMP_NAME, $workOrder);

        $response = $this->actingAs($this->makeStaff())->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();
        $this->assertSame(self::JOBBER_URI, $response->json('jobber_web_uri'));
    }

    public function test_staff_do_not_receive_the_jobber_link_without_thmp(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeVendorUser('Reliable Plumbing', $workOrder);

        $response = $this->actingAs($this->makeStaff('admin'))->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();
        $this->assertArrayNotHasKey('jobber_web_uri', $response->json());
        $this->assertArrayNotHasKey('jobber_job_gid', $response->json());
    }

    public function test_the_thmp_vendor_account_receives_the_jobber_link(): void
    {
        $workOrder = $this->makeWorkOrder();
        $thmpUser = $this->makeVendorUser(Vendor::THMP_NAME, $workOrder);

        $response = $this->actingAs($thmpUser)->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();
        $this->assertSame(self::JOBBER_URI, $response->json('jobber_web_uri'));
    }

    public function test_a_third_party_vendor_never_receives_the_jobber_link(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->makeVendorUser(Vendor::THMP_NAME, $workOrder);
        $thirdParty = $this->makeVendorUser('Reliable Plumbing', $workOrder);

        $response = $this->actingAs($thirdParty)->get(route('work_orders.data', $workOrder->id));

        $response->assertOk();
        $this->assertArrayNotHasKey('jobber_web_uri', $response->json());
        $this->assertArrayNotHasKey('jobber_job_gid', $response->json());
    }

    public function test_the_details_page_flag_follows_the_same_rule(): void
    {
        $thmpWorkOrder = $this->makeWorkOrder();
        $this->makeVendorUser(Vendor::THMP_NAME, $thmpWorkOrder);

        $staff = $this->makeStaff();

        $this->assertTrue(
            $this->detailsPage($staff, $thmpWorkOrder)->json('props.canViewJobberLink')
        );

        $thirdPartyWorkOrder = $this->makeWorkOrder();
        $this->makeVendorUser('Reliable Plumbing', $thirdPartyWorkOrder);

        $this->assertFalse(
            $this->detailsPage($staff, $thirdPartyWorkOrder)->json('props.canViewJobberLink')
        );
    }

    public function test_the_vendor_work_order_list_withholds_the_jobber_link_from_third_parties(): void
    {
        $workOrder = $this->makeWorkOrder();
        $thirdParty = $this->makeVendorUser('Reliable Plumbing', $workOrder);

        $response = $this->actingAs($thirdParty)->get(route('work_orders.vendor'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
            'X-Inertia-Partial-Data' => 'workOrders',
            'X-Inertia-Partial-Component' => 'WorkOrder/VendorWorkOrders',
        ]);

        $response->assertOk();

        $listed = $response->json('props.workOrders');

        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('jobber_web_uri', $listed[0]);
    }
}
