<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\JobberWorkOrderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Scheduled Visits page tells each visit which work order it belongs to,
 * so the number at the end of the visit's title can open that work order.
 */
class VisitWorkOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK_START = '2026-09-06';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(): User
    {
        return User::factory()->create()->assignRole('woc');
    }

    private function makeVendor(): Vendor
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        return Vendor::query()->create([
            'propertyware_id' => 'V-'.(++$this->sequence),
            'name' => 'Test Vendor '.$this->sequence,
            'vendor_type' => 'Handyman',
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    /**
     * A Jobber job with one visit inside the tested week. The visit title
     * defaults to the job title, the way Jobber fills it in.
     */
    private function makeVisit(string $jobTitle, ?string $visitTitle = null, ?string $jobberGid = null): JobberVisit
    {
        $n = ++$this->sequence;

        $client = JobberClient::query()->create([
            'jobber_id' => "client-{$n}",
            'name' => "17935 Alora Springs Trce {$n}",
            'jobber_web_uri' => 'https://example.test/client',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => "property-{$n}",
            'jobber_client_id' => $client->id,
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => $jobberGid ?? "job-{$n}",
            'job_number' => (string) (20000 + $n),
            'title' => $jobTitle,
            'job_status' => 'active',
            'jobber_web_uri' => 'https://example.test/job',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberVisit::query()->create([
            'jobber_id' => "visit-{$n}",
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'title' => $visitTitle ?? $jobTitle,
            'start_at' => '2026-09-09 09:00:00',
            'end_at' => '2026-09-09 10:00:00',
        ]);
    }

    /**
     * The events the page renders for the tested week, keyed by visit id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventsFor(User $user): array
    {
        $events = [];

        $this->actingAs($user)
            ->get(route('visits.index', ['week_start' => self::WEEK_START]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$events) {
                $page->component('Inspection/Schedules');
                $events = $page->toArray()['props']['events'];
            });

        return collect($events)->keyBy('id')->all();
    }

    public function test_a_visit_links_to_the_work_order_carrying_its_jobber_id(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 44046, 'jobber_job_gid' => 'gid://Jobber/Job/1']);
        $visit = $this->makeVisit('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046', null, 'gid://Jobber/Job/1');

        $events = $this->eventsFor($this->staff());

        $this->assertSame(['id' => $workOrder->id, 'work_order_no' => 44046], $events[$visit->id]['work_order']);
    }

    public function test_a_visit_links_by_the_number_at_the_end_of_the_job_title(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 44046]);
        $visit = $this->makeVisit(
            '17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046',
            '17935 Alora Springs Trce - 17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046',
        );

        $events = $this->eventsFor($this->staff());

        $this->assertSame($workOrder->id, $events[$visit->id]['work_order']['id']);
        $this->assertSame(44046, $events[$visit->id]['work_order']['work_order_no']);
    }

    public function test_the_visit_title_is_read_when_only_it_carries_the_number(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43871]);
        $visit = $this->makeVisit('Zone 3 - HVAC', '9104 Lakes At 610 Dr - Zone 3 - HVAC - #43871');

        $events = $this->eventsFor($this->staff());

        $this->assertSame($workOrder->id, $events[$visit->id]['work_order']['id']);
    }

    public function test_a_legacy_title_ending_in_the_bare_number_links_too(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 43871]);
        $visit = $this->makeVisit('9104 Lakes At 610 Dr - Zone 3 - HVAC - 43871');

        $events = $this->eventsFor($this->staff());

        $this->assertSame($workOrder->id, $events[$visit->id]['work_order']['id']);
    }

    public function test_a_tenant_benefit_package_visit_has_no_work_order(): void
    {
        WorkOrder::factory()->create(['work_order_no' => 2026]);
        $visit = $this->makeVisit('17007 Maravillas Cove Dr - Zone 2 - Q3 2026 Tenant Benefit Package');

        $events = $this->eventsFor($this->staff());

        $this->assertArrayHasKey('work_order', $events[$visit->id]);
        $this->assertNull($events[$visit->id]['work_order']);
    }

    public function test_a_number_nobody_has_yields_no_work_order(): void
    {
        $visit = $this->makeVisit('123 Nowhere Ln - Zone 1 - Plumbing - #99999');

        $events = $this->eventsFor($this->staff());

        $this->assertNull($events[$visit->id]['work_order']);
    }

    public function test_the_lowest_work_order_id_wins_when_two_share_the_number(): void
    {
        $first = WorkOrder::factory()->create(['work_order_no' => 44046]);
        WorkOrder::factory()->create(['work_order_no' => 44046]);
        $visit = $this->makeVisit('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046');

        $events = $this->eventsFor($this->staff());

        $this->assertSame($first->id, $events[$visit->id]['work_order']['id']);
    }

    public function test_the_stored_jobber_id_beats_a_mismatching_title_number(): void
    {
        $byGid = WorkOrder::factory()->create(['work_order_no' => 44001, 'jobber_job_gid' => 'gid://Jobber/Job/7']);
        WorkOrder::factory()->create(['work_order_no' => 44046]);
        $visit = $this->makeVisit('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046', null, 'gid://Jobber/Job/7');

        $events = $this->eventsFor($this->staff());

        $this->assertSame($byGid->id, $events[$visit->id]['work_order']['id']);
        $this->assertSame(44001, $events[$visit->id]['work_order']['work_order_no']);
    }

    public function test_the_visit_details_json_carries_the_same_link(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 44046]);
        $visit = $this->makeVisit('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046');
        $tbp = $this->makeVisit('17007 Maravillas Cove Dr - Zone 2 - Q3 2026 Tenant Benefit Package');

        $this->actingAs($this->staff())
            ->getJson(route('visits.details', $visit))
            ->assertOk()
            ->assertJsonPath('work_order.id', $workOrder->id)
            ->assertJsonPath('work_order.work_order_no', 44046);

        $this->actingAs($this->staff())
            ->getJson(route('visits.details', $tbp))
            ->assertOk()
            ->assertJsonPath('work_order', null);
    }

    public function test_a_vendor_only_gets_the_link_to_a_work_order_they_are_on(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 44046, 'status' => 'Open']);
        $visit = $this->makeVisit('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046');

        $assigned = $this->makeVendor();
        $workOrder->vendors()->attach($assigned->id);
        $other = $this->makeVendor();

        $this->assertSame($workOrder->id, $this->eventsFor($assigned->user)[$visit->id]['work_order']['id']);
        $this->assertNull($this->eventsFor($other->user)[$visit->id]['work_order']);
    }

    public function test_the_number_is_read_only_from_the_end_of_a_title(): void
    {
        $this->assertSame(44046, JobberWorkOrderResolver::numberFromTitle('17935 Alora Springs Trace - Zone 0 - Light Fixture - #44046'));
        $this->assertSame(44046, JobberWorkOrderResolver::numberFromTitle('Light Fixture - # 44046  '));
        $this->assertSame(43871, JobberWorkOrderResolver::numberFromTitle('9104 Lakes At 610 Dr - Zone 3 - HVAC - 43871'));
        $this->assertSame(143967, JobberWorkOrderResolver::numberFromTitle('Zone 1 - Roof - #143967'));
        $this->assertNull(JobberWorkOrderResolver::numberFromTitle('17007 Maravillas Cove Dr - Zone 2 - Q3 2026 Tenant Benefit Package'));
        $this->assertNull(JobberWorkOrderResolver::numberFromTitle('Zone 2 - Unit 101'));
        $this->assertNull(JobberWorkOrderResolver::numberFromTitle('#44046 - Zone 0 - Light Fixture'));
        $this->assertNull(JobberWorkOrderResolver::numberFromTitle(''));
        $this->assertNull(JobberWorkOrderResolver::numberFromTitle(null));
    }
}
