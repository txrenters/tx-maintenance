<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeJob(string $number = '5001'): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => 'Outside Client',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => $number,
            'title' => 'Roof patch',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVendor(User $user): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Roofing Co',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_guest_cannot_read_client_contacts(): void
    {
        $job = $this->makeJob();

        $this->getJson(route('client-contacts.index', ['jobber' => $job->id]))
            ->assertUnauthorized();
    }

    public function test_a_guest_cannot_replace_client_contacts(): void
    {
        $job = $this->makeJob();

        $this->postJson(route('client-contacts.store', ['jobber' => $job->id]), [
            'contacts' => [['name' => 'Mallory', 'phone' => '2145550000']],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('client_contacts', 0);
    }

    public function test_a_vendor_cannot_open_the_staff_jobs_board(): void
    {
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $this->makeVendor($vendorUser);

        $this->actingAs($vendorUser)->get(route('inspections.index'))->assertForbidden();
    }

    public function test_a_vendor_cannot_open_an_unassigned_jobs_details_page(): void
    {
        $job = $this->makeJob();
        $vendorUser = User::factory()->create()->assignRole('vendor');
        $this->makeVendor($vendorUser);

        $this->actingAs($vendorUser)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertForbidden();
    }

    public function test_a_tenant_cannot_open_the_staff_jobs_board(): void
    {
        $tenant = User::factory()->create()->assignRole('tenant');

        $this->actingAs($tenant)->get(route('inspections.index'))->assertForbidden();
    }

    public function test_staff_get_the_job_page_with_the_vendor_photo_and_invoice_data(): void
    {
        $job = $this->makeJob();
        $vendorUser = User::factory()->create();
        $vendor = $this->makeVendor($vendorUser);
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inspection/Show')
                ->where('canAssignVendors', true)
                ->has('vendorOptions')
                ->has('job.vendors', 1)
                ->where('job.vendors.0.name', 'Roofing Co')
                ->has('job.attachments')
                ->has('job.invoices')
                ->has('job.notes')
                ->where('canManageNotes', true)
            );
    }

    public function test_the_board_modal_json_carries_the_vendor_picker_and_gates(): void
    {
        $job = $this->makeJob();
        $vendorUser = User::factory()->create();
        $vendor = $this->makeVendor($vendorUser);
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->getJson(route('jobber.jobDetails', ['job' => $job->id, 'format' => 'json']))
            ->assertOk()
            ->assertJsonPath('can_assign_vendors', true)
            ->assertJsonPath('can_upload_invoices', true)
            ->assertJsonPath('can_manage_notes', true)
            ->assertJsonPath('vendors.0.name', 'Roofing Co')
            ->assertJsonPath('vendor_ids.0', $vendor->id)
            ->assertJsonStructure(['vendor_options', 'attachments', 'invoices', 'notes']);
    }

    /**
     * Anyone in the office may work these jobs — only outside parties are kept
     * out — so accounting gets the same abilities as admin and WOC.
     */
    public function test_accounting_can_assign_vendors_and_handle_invoices(): void
    {
        $job = $this->makeJob();
        $accounting = User::factory()->create()->assignRole('accounting');

        $this->actingAs($accounting)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canAssignVendors', true)
                ->where('canUploadInvoices', true)
            );

        $vendor = $this->makeVendor(User::factory()->create());

        $this->actingAs($accounting)
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('jobber_job_vendors', [
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_a_user_with_no_role_cannot_reach_a_job(): void
    {
        $job = $this->makeJob();
        $nobody = User::factory()->create();

        $this->actingAs($nobody)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertForbidden();
    }

    public function test_an_owner_cannot_upload_or_approve_invoices(): void
    {
        $job = $this->makeJob();
        $owner = User::factory()->create()->assignRole('owner');

        $this->actingAs($owner)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => []])
            ->assertForbidden();
    }

    public function test_a_vendor_only_sees_their_own_assigned_jobs(): void
    {
        $mineJob = $this->makeJob('5001');
        $otherJob = $this->makeJob('5002');

        $vendorUser = User::factory()->create()->assignRole('vendor');
        $vendor = $this->makeVendor($vendorUser);

        $mineJob->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        $this->actingAs($vendorUser)
            ->get(route('jobber.vendor_jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inspection/VendorJobs')
                ->has('jobs', 1)
                ->where('jobs.0.job_number', '5001')
            );

        $this->assertNotSame($mineJob->id, $otherJob->id);
    }

    public function test_a_vendor_role_user_with_no_vendor_record_gets_an_empty_list_not_an_error(): void
    {
        $orphan = User::factory()->create()->assignRole('vendor');

        $this->actingAs($orphan)
            ->get(route('jobber.vendor_jobs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('jobs', 0));
    }
}
