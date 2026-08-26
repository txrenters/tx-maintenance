<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobNote;
use App\Models\JobberProperty;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JobberJobNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeJob(string $number = '6101'): Jobber
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

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function makeNote(Jobber $job, ?User $user, string $body = 'Owner wants a call before any work'): JobberJobNote
    {
        return JobberJobNote::query()->create([
            'jobber_job_id' => $job->id,
            'user_id' => $user?->id,
            'body' => $body,
        ]);
    }

    public function test_office_staff_can_add_a_note(): void
    {
        foreach (['admin', 'woc', 'accounting'] as $index => $role) {
            $user = $this->staff($role);
            $job = $this->makeJob('620'.$index);

            $this->actingAs($user)
                ->post(route('jobber.notes.store', $job), ['body' => "Note from {$role}"])
                ->assertRedirect();

            $this->assertDatabaseHas('jobber_job_notes', [
                'jobber_job_id' => $job->id,
                'user_id' => $user->id,
                'body' => "Note from {$role}",
            ]);
        }
    }

    public function test_staff_can_delete_a_note(): void
    {
        $job = $this->makeJob();
        $note = $this->makeNote($job, $this->staff('admin'));

        $this->actingAs($this->staff())
            ->delete(route('jobber.notes.destroy', $note))
            ->assertRedirect();

        $this->assertDatabaseCount('jobber_job_notes', 0);
    }

    public function test_a_vendor_cannot_add_or_delete_a_note(): void
    {
        $job = $this->makeJob();
        $note = $this->makeNote($job, $this->staff());
        $vendor = $this->staff('vendor');

        $this->actingAs($vendor)
            ->post(route('jobber.notes.store', $job), ['body' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($vendor)
            ->delete(route('jobber.notes.destroy', $note))
            ->assertForbidden();

        $this->assertDatabaseCount('jobber_job_notes', 1);
    }

    public function test_a_tenant_owner_or_roleless_user_cannot_add_a_note(): void
    {
        $job = $this->makeJob();

        foreach ([$this->staff('tenant'), $this->staff('owner'), User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->post(route('jobber.notes.store', $job), ['body' => 'Nope'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('jobber_job_notes', 0);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $job = $this->makeJob();
        $note = $this->makeNote($job, $this->staff());

        $this->post(route('jobber.notes.store', $job), ['body' => 'Nope'])
            ->assertRedirect(route('login'));

        $this->delete(route('jobber.notes.destroy', $note))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('jobber_job_notes', 1);
    }

    public function test_an_empty_note_is_rejected(): void
    {
        $job = $this->makeJob();
        $user = $this->staff();

        foreach (['', '   '] as $body) {
            $this->actingAs($user)
                ->from(route('inspections.index'))
                ->post(route('jobber.notes.store', $job), ['body' => $body])
                ->assertRedirect(route('inspections.index'))
                ->assertSessionHasErrors('body');
        }

        $this->actingAs($user)
            ->postJson(route('jobber.notes.store', $job), ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('jobber_job_notes', 0);
    }

    public function test_an_overlong_note_is_rejected(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->staff())
            ->post(route('jobber.notes.store', $job), ['body' => str_repeat('a', 5001)])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('jobber_job_notes', 0);
    }

    public function test_the_board_modal_json_carries_notes_newest_first_with_the_author(): void
    {
        $user = $this->staff();
        $job = $this->makeJob();

        $this->makeNote($job, $user, 'First note');
        $this->makeNote($job, $user, 'Second note');

        $this->actingAs($user)
            ->getJson(route('jobber.jobDetails', ['job' => $job->id, 'format' => 'json']))
            ->assertOk()
            ->assertJsonPath('notes_count', 2)
            ->assertJsonPath('notes.0.body', 'Second note')
            ->assertJsonPath('notes.0.author', $user->name)
            ->assertJsonPath('notes.1.body', 'First note')
            ->assertJsonPath('can_manage_notes', true)
            ->assertJsonStructure(['notes' => [['id', 'body', 'author', 'created_at']]]);
    }

    public function test_the_job_page_carries_notes(): void
    {
        $user = $this->staff('admin');
        $job = $this->makeJob();
        $this->makeNote($job, $user);

        $this->actingAs($user)
            ->get(route('jobber.jobDetails', ['job' => $job->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inspection/Show')
                ->where('canManageNotes', true)
                ->has('job.notes', 1)
                ->where('job.notes.0.author', $user->name)
                ->where('job.notes_count', 1)
            );
    }

    public function test_a_note_survives_its_author_being_deleted(): void
    {
        $author = $this->staff();
        $job = $this->makeJob();
        $note = $this->makeNote($job, $author);

        $author->delete();

        $this->assertNull($note->fresh()->user_id);

        $this->actingAs($this->staff('admin'))
            ->getJson(route('jobber.jobDetails', ['job' => $job->id, 'format' => 'json']))
            ->assertOk()
            ->assertJsonPath('notes_count', 1)
            ->assertJsonPath('notes.0.author', 'Unknown');
    }

    public function test_notes_can_still_be_added_to_a_closed_job(): void
    {
        $user = $this->staff();
        $job = $this->makeJob();

        $this->actingAs($user)->post(route('jobber.close', $job));

        $this->actingAs($user)
            ->post(route('jobber.notes.store', $job), ['body' => 'Invoiced and paid'])
            ->assertRedirect();

        $this->assertDatabaseHas('jobber_job_notes', ['jobber_job_id' => $job->id, 'body' => 'Invoiced and paid']);
    }

    public function test_deleting_the_job_removes_its_notes(): void
    {
        $user = $this->staff('admin');
        $job = $this->makeJob();
        $this->makeNote($job, $user);

        $this->actingAs($user)
            ->delete(route('inspections.destroy', $job))
            ->assertRedirect();

        $this->assertDatabaseCount('jobber_jobs', 0);
        $this->assertDatabaseCount('jobber_job_notes', 0);
    }

    public function test_a_jobber_sync_overwriting_the_job_leaves_notes_alone(): void
    {
        $job = $this->makeJob();
        $this->makeNote($job, $this->staff());

        // Stand in for the importer / JOB_UPDATE webhook writing Jobber's values.
        $job->update(['instructions' => 'Fresh from Jobber', 'job_status' => 'active']);

        $this->assertDatabaseCount('jobber_job_notes', 1);
        $this->assertSame(1, $job->fresh()->jobNotes()->count());
    }

    public function test_notes_never_reach_the_vendor_portal(): void
    {
        $job = $this->makeJob();
        $this->makeNote($job, $this->staff(), 'Tenant is behind on rent, keep it quiet');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Roofing Co',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $token = Str::random(64);
        $job->vendors()->attach($vendor->id, ['access_token' => $token]);

        $this->get(route('jobber.portal.show', $token))
            ->assertOk()
            ->assertDontSee('Tenant is behind on rent');
    }
}
