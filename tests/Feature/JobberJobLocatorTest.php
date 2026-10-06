<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\WorkOrder;
use App\Services\JobberJobLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobberJobLocatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(string $gid, ?string $title, ?string $webUri = null): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '151 Island Blvd',
            'jobber_web_uri' => 'https://example.test/client',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => $gid,
            'job_number' => (string) random_int(20000, 29999),
            'title' => $title,
            'job_status' => 'active',
            'jobber_web_uri' => $webUri,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVisit(Jobber $job, ?string $title): JobberVisit
    {
        return JobberVisit::query()->create([
            'jobber_id' => 'visit-'.uniqid(),
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $job->jobber_client_id,
            'jobber_property_id' => $job->jobber_property_id,
            'title' => $title,
        ]);
    }

    private function locator(): JobberJobLocator
    {
        return app(JobberJobLocator::class);
    }

    /**
     * The 2026-10-05 case: the office made a job by hand with a blank title
     * and typed the work order number into the visit. Nothing else links it.
     */
    public function test_a_job_is_found_through_its_visit_title_when_nothing_else_links_it(): void
    {
        $job = $this->makeJob('job-hand-made', '');
        $this->makeVisit($job, '151 Island Blvd - 151 Island Blvd - Zone 2 - General Maintenance - #44321');

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 44321,
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
        ]);

        $this->assertSame($job->id, $this->locator()->forWorkOrder($workOrder)?->id);
    }

    /**
     * A visit suffix cannot hit a longer number, exactly like the job title
     * rule: "#144321" does not end in "#44321".
     */
    public function test_a_visit_title_with_a_longer_number_does_not_match(): void
    {
        $job = $this->makeJob('job-other', '');
        $this->makeVisit($job, 'Zone 2 - General Maintenance - #144321');

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 44321,
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
        ]);

        $this->assertNull($this->locator()->forWorkOrder($workOrder));
        $this->assertCount(0, $this->locator()->allForWorkOrder($workOrder));
    }

    /**
     * Every job that is about the work order, linked one first, each once:
     * the linked (never scheduled) job, plus the hand-made one the crew
     * actually worked, found through its visit.
     */
    public function test_all_jobs_about_a_work_order_are_listed_linked_first_without_duplicates(): void
    {
        $handMade = $this->makeJob('job-hand-made', '');
        $this->makeVisit($handMade, '151 Island Blvd - Zone 2 - General Maintenance - #44321');
        // Two visits on the same job must not list the job twice.
        $this->makeVisit($handMade, 'Return trip - #44321');

        $linked = $this->makeJob('job-linked', '151 Island Blvd - Zone 2 - General Maintenance - #44321', 'https://secure.getjobber.com/work_orders/1');

        $unrelated = $this->makeJob('job-unrelated', 'Zone 4 - Move out inspection');
        $this->makeVisit($unrelated, 'Zone 4 - Move out inspection');

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 44321,
            'jobber_job_gid' => 'job-linked',
            'jobber_web_uri' => 'https://secure.getjobber.com/work_orders/1',
        ]);

        $all = $this->locator()->allForWorkOrder($workOrder);

        $this->assertSame([$linked->id, $handMade->id], $all->pluck('id')->all());
        $this->assertSame($linked->id, $this->locator()->forWorkOrder($workOrder)?->id);
    }

    public function test_a_work_order_nothing_links_has_no_jobs(): void
    {
        $this->makeJob('job-somewhere', 'Zone 1 - Home Cleaning - #44999');

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 44321,
            'jobber_job_gid' => null,
            'jobber_web_uri' => null,
        ]);

        $this->assertNull($this->locator()->forWorkOrder($workOrder));
        $this->assertTrue($this->locator()->allForWorkOrder($workOrder)->isEmpty());
    }
}
