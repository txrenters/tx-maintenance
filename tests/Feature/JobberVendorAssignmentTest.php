<?php

namespace Tests\Feature;

use App\Jobs\SendJobberVendorAssignment;
use App\Jobs\SendOwnerVendorAssignmentEmail;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Mail\JobberVendorAssignmentMail;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberVendorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config([
            'services.jobber.vendor_assign_enabled' => true,
            'services.jobber.vendor_notify_enabled' => false,
        ]);
    }

    private function makeJob(): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => 'Outside Client',
            'jobber_web_uri' => 'https://example.test',
            'first_name' => 'Outside',
            'last_name' => 'Client',
            'phone' => '2145550111',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '100 Elm St',
            'city' => 'Dallas',
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '2001',
            'title' => 'Water heater replacement',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        $user = User::factory()->create();
        $user->forceFill(['phone' => '2815550000'])->save();

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_an_admin_can_assign_a_vendor_to_a_jobber_job(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();

        $this->actingAs($this->staff())
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('jobber_job_vendors', [
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
        ]);

        $token = $job->fresh()->vendors->first()->pivot->access_token;
        $this->assertNotEmpty($token);
        $this->assertSame(48, strlen($token));

        Queue::assertPushed(SendJobberVendorAssignment::class, 1);
    }

    public function test_each_assignment_gets_its_own_unique_token(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $one = $this->makeVendor('Vendor One');
        $two = $this->makeVendor('Vendor Two');

        $this->actingAs($this->staff())
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$one->id, $two->id]]);

        $tokens = $job->fresh()->vendors->pluck('pivot.access_token');

        $this->assertCount(2, $tokens->filter());
        $this->assertCount(2, $tokens->unique());
    }

    public function test_re_saving_the_same_vendor_list_does_not_notify_again(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $admin = $this->staff();

        $this->actingAs($admin)->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]]);
        $this->actingAs($admin)->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]]);

        Queue::assertPushed(SendJobberVendorAssignment::class, 1);
    }

    public function test_no_email_or_text_is_sent_while_the_notify_gate_is_off(): void
    {
        Mail::fake();
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobber_text_messages', 0);
    }

    public function test_the_vendor_is_emailed_and_texted_once_the_gate_is_on(): void
    {
        Mail::fake();
        Queue::fake();
        config([
            'services.jobber.vendor_notify_enabled' => true,
            'services.twilio.from' => '+15125550100',
        ]);

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('b', 48)]);

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertSent(JobberVendorAssignmentMail::class);
        $this->assertDatabaseHas('jobber_text_messages', [
            'jobber_id' => $job->id,
            'receiver_number' => '+12815550000',
        ]);
    }

    public function test_a_repeat_run_of_the_notification_job_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();
        config([
            'services.jobber.vendor_notify_enabled' => true,
            'services.twilio.from' => '+15125550100',
        ]);

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('c', 48)]);

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();
        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertSentCount(1);
        $this->assertDatabaseCount('jobber_text_messages', 1);
    }

    public function test_thmp_assignment_sends_no_email_or_text(): void
    {
        Mail::fake();
        Queue::fake();
        config(['services.jobber.vendor_notify_enabled' => true]);

        $job = $this->makeJob();
        $thmp = $this->makeVendor(Vendor::THMP_NAME);
        $job->vendors()->attach($thmp->id, ['access_token' => str_repeat('d', 48)]);

        (new SendJobberVendorAssignment($job->id, $thmp->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobber_text_messages', 0);
    }

    public function test_assignment_never_dispatches_owner_or_tenant_work_order_notifications(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();

        $this->actingAs($this->staff())
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]]);

        Queue::assertNotPushed(SendOwnerVendorAssignmentEmail::class);
        Queue::assertNotPushed(SendVendorWorkOrderInformation::class);
    }

    public function test_assignment_never_calls_propertyware(): void
    {
        Queue::fake();
        Http::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();

        $this->actingAs($this->staff())
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]]);

        Http::assertNothingSent();
    }

    public function test_a_vendor_role_user_cannot_assign_vendors(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $vendorUser = User::factory()->create()->assignRole('vendor');

        $this->actingAs($vendorUser)
            ->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]])
            ->assertForbidden();

        $this->assertDatabaseCount('jobber_job_vendors', 0);
    }

    public function test_detaching_a_vendor_invalidates_their_portal_link(): void
    {
        Queue::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $admin = $this->staff();

        $this->actingAs($admin)->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => [$vendor->id]]);
        $token = $job->fresh()->vendors->first()->pivot->access_token;

        $this->get(route('jobber.portal.show', $token))->assertOk();

        $this->actingAs($admin)->put(route('jobber.vendors.change', $job->id), ['vendor_ids' => []]);

        $this->get(route('jobber.portal.show', $token))->assertNotFound();
    }
}
