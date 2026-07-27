<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\TenantEmailAttachment;
use App\Models\TenantEmailNotification;
use App\Models\Tenants;
use App\Models\User;
use App\Services\TenantJobberEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('woc', 'web');
        Role::findOrCreate('tenant', 'web');
    }

    public function test_index_returns_only_the_requested_assigned_job_thread(): void
    {
        $tenant = Tenants::factory()->create(['email' => 'tenant@example.com']);
        $jobA = $this->assignedJob($tenant);
        $jobB = $this->assignedJob($tenant);
        TenantEmailNotification::factory()->create([
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $jobA->id,
            'subject' => 'Job A',
        ]);
        TenantEmailNotification::factory()->create([
            'tenant_id' => $tenant->id,
            'jobber_job_id' => $jobB->id,
            'subject' => 'Job B',
        ]);

        $response = $this->actingAs($this->woc())
            ->getJson(route('tenant.email.index', $tenant).'?jobber_job_id='.$jobA->id);

        $response->assertOk();
        $subjects = collect($response->json('tenant_emails'))->pluck('subject');
        $this->assertTrue($subjects->contains('Job A'));
        $this->assertFalse($subjects->contains('Job B'));
    }

    public function test_index_rejects_a_job_not_assigned_to_the_tenant(): void
    {
        $tenant = Tenants::factory()->create();
        $job = $this->assignedJob(Tenants::factory()->create());

        $this->actingAs($this->woc())
            ->getJson(route('tenant.email.index', $tenant).'?jobber_job_id='.$job->id)
            ->assertNotFound();
    }

    public function test_store_sends_email_for_an_assigned_job(): void
    {
        $tenant = Tenants::factory()->create();
        $job = $this->assignedJob($tenant);
        $sender = Mockery::mock(TenantJobberEmailSender::class);
        $sender->shouldReceive('send')
            ->once();
        $this->app->instance(TenantJobberEmailSender::class, $sender);

        $this->actingAs($this->woc())
            ->post(route('tenant.email.send', $tenant), [
                'jobber_job_id' => $job->id,
                'from_email' => 'coordinator@texasrenters.com',
                'to' => 'tenant-test@example.com',
                'subject' => 'Visit update',
                'body' => '<p>Your visit is scheduled.</p>',
                'attachments' => [UploadedFile::fake()->create('visit.pdf', 5, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_store_sends_manual_email_without_a_jobber_job(): void
    {
        $tenant = Tenants::factory()->create();
        $sender = Mockery::mock(TenantJobberEmailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(fn ($sentTenant, $job, $to): bool => $sentTenant->is($tenant)
                && $job === null
                && $to === 'tenant-test@example.com');
        $this->app->instance(TenantJobberEmailSender::class, $sender);

        $this->actingAs($this->woc())
            ->post(route('tenant.email.send', $tenant), [
                'from_email' => 'coordinator@texasrenters.com',
                'to' => 'tenant-test@example.com',
                'subject' => 'General update',
                'body' => '<p>Hello.</p>',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_store_validates_recipient_content_and_assigned_job(): void
    {
        $tenant = Tenants::factory()->create();
        $job = $this->assignedJob(Tenants::factory()->create());

        $this->actingAs($this->woc())
            ->post(route('tenant.email.send', $tenant), [
                'jobber_job_id' => $job->id,
            ])
            ->assertSessionHasErrors(['subject', 'body', 'from_email', 'to']);
    }

    public function test_tenant_users_cannot_read_or_send_email_threads(): void
    {
        $tenant = Tenants::factory()->create();
        $job = $this->assignedJob($tenant);
        $tenantUser = $tenant->user;
        $tenantUser->assignRole('tenant');

        $this->actingAs($tenantUser)
            ->getJson(route('tenant.email.index', $tenant).'?jobber_job_id='.$job->id)
            ->assertForbidden();

        $this->actingAs($tenantUser)
            ->post(route('tenant.email.send', $tenant), [
                'jobber_job_id' => $job->id,
                'to' => $tenant->email,
                'subject' => 'Unauthorized',
                'body' => '<p>No</p>',
            ])
            ->assertForbidden();
    }

    public function test_authorized_user_can_download_a_stored_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tenant-email-attachments/test/file.pdf', 'PDF contents');
        $message = TenantEmailNotification::factory()->create();
        $attachment = TenantEmailAttachment::query()->create([
            'tenant_email_notification_id' => $message->id,
            'filename' => 'visit.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
            'path' => 'tenant-email-attachments/test/file.pdf',
        ]);

        $this->actingAs($this->woc())
            ->get(route('tenant.email.attachment', $attachment))
            ->assertOk()
            ->assertDownload('visit.pdf');

        $tenantUser = $message->tenant->user;
        $tenantUser->assignRole('tenant');

        $this->actingAs($tenantUser)
            ->get(route('tenant.email.attachment', $attachment))
            ->assertForbidden();
    }

    private function assignedJob(Tenants $tenant): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => fake()->unique()->uuid(),
            'name' => 'Test Client',
            'jobber_web_uri' => 'https://example.com/client',
        ]);
        $property = JobberProperty::query()->create([
            'jobber_id' => fake()->unique()->uuid(),
            'jobber_client_id' => $client->id,
        ]);
        $job = Jobber::query()->create([
            'jobber_id' => fake()->unique()->uuid(),
            'job_number' => fake()->unique()->numerify('####'),
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
        $tenant->jobberJobs()->attach($job);

        return $job;
    }

    private function woc(): User
    {
        return User::factory()->create()->assignRole('woc');
    }
}
