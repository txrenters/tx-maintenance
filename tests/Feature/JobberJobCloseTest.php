<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberJobCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeJob(string $number = '6001', string $status = 'active'): Jobber
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
            'job_status' => $status,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_office_staff_can_close_a_job(): void
    {
        $user = $this->staff();
        $job = $this->makeJob();

        $this->actingAs($user)
            ->post(route('jobber.close', $job), ['close_reason' => 'Work finished and invoiced'])
            ->assertRedirect();

        $job->refresh();

        $this->assertNotNull($job->closed_at);
        $this->assertSame($user->id, $job->closed_by_user_id);
        $this->assertSame('Work finished and invoiced', $job->close_reason);
    }

    public function test_accounting_and_admin_can_close_a_job(): void
    {
        foreach (['admin', 'accounting'] as $index => $role) {
            $job = $this->makeJob('700'.$index);

            $this->actingAs($this->staff($role))
                ->post(route('jobber.close', $job))
                ->assertRedirect();

            $this->assertNotNull($job->fresh()->closed_at);
        }
    }

    public function test_a_vendor_cannot_close_a_job(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->staff('vendor'))
            ->post(route('jobber.close', $job))
            ->assertForbidden();

        $this->assertNull($job->fresh()->closed_at);
    }

    public function test_a_tenant_owner_or_roleless_user_cannot_close_a_job(): void
    {
        foreach (['tenant', 'owner'] as $role) {
            $job = $this->makeJob();

            $this->actingAs($this->staff($role))
                ->post(route('jobber.close', $job))
                ->assertForbidden();

            $this->assertNull($job->fresh()->closed_at);
        }

        $job = $this->makeJob();

        $this->actingAs(User::factory()->create())
            ->post(route('jobber.close', $job))
            ->assertForbidden();

        $this->assertNull($job->fresh()->closed_at);
    }

    public function test_closing_an_already_closed_job_keeps_the_original_record(): void
    {
        $first = $this->staff();
        $job = $this->makeJob();

        $this->actingAs($first)->post(route('jobber.close', $job), ['close_reason' => 'First']);
        $closedAt = $job->fresh()->closed_at;

        $this->actingAs($this->staff('admin'))->post(route('jobber.close', $job), ['close_reason' => 'Second']);

        $job->refresh();

        $this->assertSame($first->id, $job->closed_by_user_id);
        $this->assertSame('First', $job->close_reason);
        $this->assertEquals($closedAt->toDateTimeString(), $job->closed_at->toDateTimeString());
    }

    public function test_staff_can_reopen_a_closed_job(): void
    {
        $job = $this->makeJob();
        $user = $this->staff();

        $this->actingAs($user)->post(route('jobber.close', $job), ['close_reason' => 'Done']);
        $this->actingAs($user)->delete(route('jobber.reopen', $job))->assertRedirect();

        $job->refresh();

        $this->assertNull($job->closed_at);
        $this->assertNull($job->closed_by_user_id);
        $this->assertNull($job->close_reason);
    }

    /**
     * The whole reason the close lives on its own column: `job_status` is
     * Jobber's, and the importer and webhook overwrite it on every sync.
     */
    public function test_a_close_survives_a_jobber_sync_overwriting_the_status(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->staff())->post(route('jobber.close', $job));

        // Stand in for the importer / JOB_UPDATE webhook writing Jobber's value.
        $job->update(['job_status' => 'active']);

        $this->assertNotNull($job->fresh()->closed_at);
        $this->assertTrue($job->fresh()->isClosedLocally());
    }

    public function test_a_closed_job_drops_off_the_active_board(): void
    {
        $open = $this->makeJob('8001');
        $closed = $this->makeJob('8002');

        $this->actingAs($this->staff())->post(route('jobber.close', $closed));

        $active = Jobber::query()->active()->pluck('job_number');

        $this->assertTrue($active->contains('8001'));
        $this->assertFalse($active->contains('8002'));
    }

    public function test_the_active_scope_still_excludes_jobber_archived_and_closed(): void
    {
        $this->makeJob('9001', 'archived');
        $this->makeJob('9002', 'closed');
        $this->makeJob('9003', 'active');

        $this->assertSame(['9003'], Jobber::query()->active()->pluck('job_number')->all());
    }

    public function test_a_vendor_cannot_upload_to_a_closed_job_through_the_portal(): void
    {
        $job = $this->makeJob();

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Roofing Co',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $token = Str::random(64);
        $job->vendors()->attach($vendor->id, ['access_token' => $token]);

        $this->actingAs($this->staff())->post(route('jobber.close', $job));

        $this->post(route('jobber.portal.attachments', $token), [
            'title' => 'After',
            'files' => [UploadedFile::fake()->image('after.jpg')],
        ])->assertForbidden();

        $this->post(route('jobber.portal.invoice', $token), [
            'title' => 'Invoice',
            'amount' => 250,
            'filename' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $this->assertSame(0, $job->jobAttachments()->count());
        $this->assertSame(0, $job->jobInvoices()->count());
    }

    public function test_the_portal_still_opens_read_only_after_a_close(): void
    {
        $job = $this->makeJob();

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Roofing Co',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $token = Str::random(64);
        $job->vendors()->attach($vendor->id, ['access_token' => $token]);

        $this->actingAs($this->staff())->post(route('jobber.close', $job));

        $this->get(route('jobber.portal.show', $token))->assertOk();
    }

    public function test_the_job_details_payload_reports_the_close(): void
    {
        $user = $this->staff();
        $job = $this->makeJob();

        $this->actingAs($user)->post(route('jobber.close', $job), ['close_reason' => 'All done']);

        $this->actingAs($user)
            ->getJson(route('jobber.jobDetails', $job).'?format=json')
            ->assertOk()
            ->assertJsonPath('is_closed', true)
            ->assertJsonPath('closed_by', $user->name)
            ->assertJsonPath('close_reason', 'All done')
            ->assertJsonPath('can_close', true);
    }
}
