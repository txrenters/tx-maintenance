<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobInvoice;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberVendorPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeJob(string $number = '3001'): Jobber
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
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => $number,
            'title' => 'Water heater replacement',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVendor(string $name = 'Reliable Plumbing'): Vendor
    {
        $user = User::factory()->create();

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => $name,
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);
    }

    private function assign(Jobber $job, Vendor $vendor, string $token): string
    {
        $job->vendors()->attach($vendor->id, ['access_token' => $token]);

        return $token;
    }

    public function test_a_valid_token_renders_the_job(): void
    {
        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $token = $this->assign($job, $vendor, str_repeat('a', 48));

        $this->get(route('jobber.portal.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('JobberPortal/Show')
                ->where('job.job_number', '3001')
            );
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get(route('jobber.portal.show', str_repeat('z', 48)))->assertNotFound();
    }

    public function test_a_token_for_one_job_does_not_expose_another_job(): void
    {
        $jobA = $this->makeJob('3001');
        $jobB = $this->makeJob('3002');
        $vendor = $this->makeVendor();

        $token = $this->assign($jobA, $vendor, str_repeat('b', 48));

        $this->get(route('jobber.portal.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('job.job_number', '3001'));

        $this->assertNotSame($jobA->id, $jobB->id);
    }

    public function test_the_portal_does_not_list_the_other_vendors_on_the_job(): void
    {
        $job = $this->makeJob();
        $mine = $this->makeVendor('My Plumbing');
        $other = $this->makeVendor('Someone Else Electric');

        $token = $this->assign($job, $mine, str_repeat('c', 48));
        $this->assign($job, $other, str_repeat('d', 48));

        $response = $this->get(route('jobber.portal.show', $token))->assertOk();

        $this->assertStringNotContainsString('Someone Else Electric', $response->getContent());
    }

    public function test_a_vendor_does_not_see_another_vendors_invoice(): void
    {
        $job = $this->makeJob();
        $mine = $this->makeVendor('My Plumbing');
        $other = $this->makeVendor('Someone Else Electric');

        $token = $this->assign($job, $mine, str_repeat('e', 48));
        $this->assign($job, $other, str_repeat('f', 48));

        JobberJobInvoice::query()->create([
            'jobber_job_id' => $job->id,
            'vendor_id' => $other->id,
            'title' => 'Their Secret Invoice',
            'amount' => 500,
            'filename' => 'jobber-invoices/theirs.pdf',
        ]);

        $response = $this->get(route('jobber.portal.show', $token))->assertOk();

        $this->assertStringNotContainsString('Their Secret Invoice', $response->getContent());
    }

    public function test_a_logged_in_unrelated_vendor_does_not_break_token_resolution(): void
    {
        $job = $this->makeJob();
        $mine = $this->makeVendor();
        $token = $this->assign($job, $mine, str_repeat('g', 48));

        $stranger = User::factory()->create()->assignRole('vendor');

        $this->actingAs($stranger)->get(route('jobber.portal.show', $token))->assertOk();
    }

    public function test_a_vendor_can_upload_photos_without_any_propertyware_sync(): void
    {
        Storage::fake('public');
        Queue::fake();
        Http::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $token = $this->assign($job, $vendor, str_repeat('h', 48));

        $this->post(route('jobber.portal.attachments', $token), [
            'title' => 'Finished install',
            'type' => 'after',
            'files' => [UploadedFile::fake()->image('after.jpg')],
        ])->assertRedirect();

        $this->assertDatabaseHas('jobber_job_attachments', [
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
            'uploaded_via' => 'vendor_portal',
            'type' => 'after',
        ]);

        Queue::assertNotPushed(UploadAttachment::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_a_vendor_can_upload_an_invoice_attributed_to_themselves(): void
    {
        Storage::fake('public');
        Http::fake();

        $job = $this->makeJob();
        $vendor = $this->makeVendor();
        $token = $this->assign($job, $vendor, str_repeat('i', 48));

        $this->post(route('jobber.portal.invoice', $token), [
            'title' => 'Job 3001 invoice',
            'amount' => 425.50,
            'filename' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('jobber_job_invoices', [
            'jobber_job_id' => $job->id,
            'vendor_id' => $vendor->id,
            'status' => 'approved',
        ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('invoices', 0);
    }
}
