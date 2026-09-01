<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THMP field notes arrive through the shared vendor login, so the note row can
 * only say "Texas Home Maintenance Pros". getNotes resolves the work order to
 * its Jobber job and swaps in the technician assigned to the visit nearest the
 * note's time (jobber_technician); every miss falls back to the vendor name.
 */
class WorkOrderNoteTechnicianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function makeThmpUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        Vendor::query()->create([
            'propertyware_id' => 'V-'.$user->id,
            'name' => Vendor::THMP_NAME,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeJob(array $attributes = []): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '17026 Cypresswood Glen Trl',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create(array_merge([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '20052',
            'title' => '17026 Cypresswood Glen Trl, - Zone 0 - Code Work - #43967',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeVisit(Jobber $job, array $attributes = []): JobberVisit
    {
        return JobberVisit::query()->create(array_merge([
            'jobber_id' => 'visit-'.uniqid(),
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $job->jobber_client_id,
            'jobber_property_id' => $job->jobber_property_id,
            'start_at' => Carbon::parse('2026-08-31 17:36:00'),
            'assigned_to' => [['id' => 'gid://Jobber/User/1', 'name' => 'Emanuel Hall']],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function note(WorkOrder $workOrder, ?User $author, array $attributes = []): WorkOrderNotes
    {
        return WorkOrderNotes::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'subject' => 'Job was completed',
            'body' => 'Installed three peep holes and C locks.',
            'user_id' => $author?->id,
            'is_private' => true,
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNotes(WorkOrder $workOrder): array
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('woc');

        return $this->actingAs($viewer)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->json('notes');
    }

    public function test_thmp_note_shows_the_technician_matched_by_jobber_gid(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);
        $this->note($workOrder, $this->makeThmpUser());

        $notes = $this->fetchNotes($workOrder);

        $this->assertSame('Emanuel Hall', $notes[0]['jobber_technician']);
    }

    public function test_job_is_found_by_web_uri_when_the_gid_is_missing(): void
    {
        $job = $this->makeJob(['jobber_web_uri' => 'https://secure.getjobber.com/work_orders/118758922']);
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create([
            'jobber_job_gid' => null,
            'jobber_web_uri' => 'https://secure.getjobber.com/work_orders/118758922',
        ]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertSame('Emanuel Hall', $this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_job_is_found_by_the_work_order_number_in_its_title(): void
    {
        $job = $this->makeJob(['title' => '17026 Cypresswood Glen Trl, - Zone 0 - Code Work - #43967']);
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create([
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
            'work_order_no' => 43967,
        ]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertSame('Emanuel Hall', $this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_a_longer_number_in_a_title_is_not_mistaken_for_the_work_order(): void
    {
        $job = $this->makeJob(['title' => 'Somewhere Else - Zone 2 - Repair - #143967']);
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create([
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
            'work_order_no' => 43967,
        ]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertNull($this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_the_visit_nearest_the_note_time_names_the_technician(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job, [
            'start_at' => Carbon::parse('2026-08-28 17:21:00'),
            'assigned_to' => [['id' => 'gid://Jobber/User/2', 'name' => 'First Tech']],
        ]);
        $this->makeVisit($job, [
            'start_at' => Carbon::parse('2026-08-31 17:36:00'),
            'assigned_to' => [['id' => 'gid://Jobber/User/3', 'name' => 'Second Tech']],
        ]);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);
        $this->note($workOrder, $this->makeThmpUser(), ['created_at' => Carbon::parse('2026-08-31 19:04:00')]);

        $this->assertSame('Second Tech', $this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_both_assignees_are_named_when_a_visit_has_two(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job, [
            'assigned_to' => [
                ['id' => 'gid://Jobber/User/1', 'name' => 'Emanuel Hall'],
                ['id' => 'gid://Jobber/User/2', 'name' => 'Jimmie Gendke'],
            ],
        ]);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertSame('Emanuel Hall, Jimmie Gendke', $this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_staff_and_other_vendor_notes_are_left_alone(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);

        $staff = User::factory()->create();
        $staff->assignRole('woc');
        $this->note($workOrder, $staff);

        $otherVendorUser = User::factory()->create();
        $otherVendorUser->assignRole('vendor');
        Vendor::query()->create([
            'propertyware_id' => 'V-other',
            'name' => 'Roofing Co',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $otherVendorUser->id,
        ]);
        $this->note($workOrder, $otherVendorUser, ['subject' => 'Roof note']);

        foreach ($this->fetchNotes($workOrder) as $note) {
            $this->assertArrayNotHasKey('jobber_technician', $note);
        }
    }

    public function test_thmp_note_falls_back_when_nothing_links_to_jobber(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
        ]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertNull($this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_thmp_note_falls_back_when_no_visit_has_an_assignee(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job, ['assigned_to' => null]);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertNull($this->fetchNotes($workOrder)[0]['jobber_technician']);
    }

    public function test_the_vendor_relation_is_not_shipped_in_the_payload(): void
    {
        $job = $this->makeJob();
        $this->makeVisit($job);
        $workOrder = WorkOrder::factory()->create(['jobber_job_gid' => $job->jobber_id]);
        $this->note($workOrder, $this->makeThmpUser());

        $this->assertArrayNotHasKey('vendor', $this->fetchNotes($workOrder)[0]['user']);
    }
}
