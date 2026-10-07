<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\OutsideCustomer;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * crystal-creek:import-jobs — one work order per Jobber job under the
 * Crystal Creek Air client, from the mirror, never calling Jobber.
 */
class ImportCrystalCreekJobsTest extends TestCase
{
    use RefreshDatabase;

    private JobberClient $crystalCreek;

    private JobberClient $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        ServiceStatus::query()->create(['name' => 'Closed', 'description' => 'Closed']);

        Vendor::query()->create([
            'propertyware_id' => Vendor::THMP_PROPERTYWARE_ID,
            'name' => Vendor::THMP_NAME,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->crystalCreek = JobberClient::query()->create([
            'jobber_id' => 'GID-CCA-CLIENT',
            'name' => 'Crystal Creek Air, LLC',
            'company_name' => 'Crystal Creek Air, LLC',
            'jobber_web_uri' => 'https://secure.getjobber.com/clients/1',
        ]);
        $this->otherClient = JobberClient::query()->create([
            'jobber_id' => 'GID-TR-CLIENT',
            'name' => '6341 Del Monte Dr',
            'jobber_web_uri' => 'https://secure.getjobber.com/clients/2',
        ]);

        config(['services.jobber.crystal_creek_client_gid' => 'GID-CCA-CLIENT']);

        Http::fake();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeJob(JobberClient $client, string $gid, string $title, array $attributes = [], ?string $street = '1234 Oak St'): Jobber
    {
        $property = JobberProperty::query()->create([
            'jobber_id' => 'PROP-'.$gid,
            'jobber_client_id' => $client->id,
            'street' => $street,
            'city' => 'Cypress',
            'province' => 'TX',
            'postal_code' => '77429',
        ]);

        return Jobber::query()->create(array_merge([
            'jobber_id' => $gid,
            'job_number' => (string) random_int(100, 999),
            'title' => $title,
            'instructions' => null,
            'job_status' => 'active',
            'jobber_web_uri' => 'https://secure.getjobber.com/work_orders/'.$gid,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'created_at_jobber' => '2026-10-01 14:00:00',
        ], $attributes));
    }

    public function test_it_creates_a_work_order_for_each_crystal_creek_job_and_none_for_other_clients(): void
    {
        $job = $this->makeJob($this->crystalCreek, 'GID-JOB-1', 'Jane Doe - AC not cooling', ['instructions' => 'Unit froze over, check charge']);
        $this->makeJob($this->otherClient, 'GID-JOB-TR', '6341 Del Monte Dr - Zone 2 - Plumbing - #43361');

        $this->artisan('crystal-creek:import-jobs')
            ->expectsOutputToContain('1 work order(s) created')
            ->assertSuccessful();

        $workOrder = WorkOrder::query()->crystalCreek()->first();
        $this->assertNotNull($workOrder);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, (int) $workOrder->work_order_no);
        $this->assertSame('GID-JOB-1', $workOrder->jobber_job_gid);
        $this->assertSame($job->jobber_web_uri, $workOrder->jobber_web_uri);
        $this->assertSame('Unit froze over, check charge', $workOrder->description);
        $this->assertSame('HVAC', $workOrder->category);
        $this->assertSame('Open', $workOrder->status);
        $this->assertSame('New', $workOrder->service_status->name);
        $this->assertStringStartsWith('2026-10-01', (string) $workOrder->created_date);
        $this->assertSame(Vendor::THMP_NAME, $workOrder->vendors()->first()?->name);

        $customer = $workOrder->outsideCustomer;
        $this->assertSame('Jane Doe', $customer->name);
        $this->assertSame('1234 Oak St', $customer->street);
        $this->assertSame('GID-CCA-CLIENT', $customer->jobber_client_gid);
        $this->assertSame('PROP-GID-JOB-1', $customer->jobber_property_gid);
        $this->assertSame('1234 Oak St, Cypress, TX 77429', $workOrder->location);

        Http::assertNothingSent();
    }

    public function test_running_twice_creates_nothing_new(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-1', 'Jane Doe - AC not cooling');

        $this->artisan('crystal-creek:import-jobs')->assertSuccessful();
        $this->artisan('crystal-creek:import-jobs')
            ->expectsOutputToContain('0 work order(s) created, 1 Jobber job(s) already had one')
            ->assertSuccessful();

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, OutsideCustomer::query()->count());
    }

    public function test_a_job_the_app_itself_created_is_not_imported_again(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-APP', 'Pat Customer - 1234 Oak St - HVAC - #7000001');
        WorkOrder::factory()->create([
            'work_order_no' => 7000001,
            'source' => WorkOrder::CRYSTAL_CREEK_SOURCE,
            'jobber_job_gid' => 'GID-JOB-APP',
        ]);

        $this->artisan('crystal-creek:import-jobs')->assertSuccessful();

        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_a_closed_jobber_job_becomes_a_closed_work_order_with_its_completion_date(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-DONE', 'Old Job - furnace', [
            'job_status' => 'archived',
            'completed_at' => '2026-08-15 16:00:00',
        ]);

        $this->artisan('crystal-creek:import-jobs')->assertSuccessful();

        $workOrder = WorkOrder::query()->crystalCreek()->first();
        $this->assertSame('Closed', $workOrder->status);
        $this->assertSame('Closed', $workOrder->service_status->name);
        $this->assertSame('2026-08-15', (string) $workOrder->completed_date);
        $this->assertEmpty(WorkOrder::query()->forBoard('crystal_creek')->pluck('id'));
    }

    public function test_two_jobs_at_the_same_address_share_one_customer(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-1', 'Jane Doe - AC not cooling', ['created_at_jobber' => '2026-09-01 10:00:00']);
        $this->makeJob($this->crystalCreek, 'GID-JOB-2', 'Jane Doe - return visit', ['created_at_jobber' => '2026-09-20 10:00:00']);

        $this->artisan('crystal-creek:import-jobs')->assertSuccessful();

        $this->assertSame(1, OutsideCustomer::query()->count());
        $this->assertSame([1, 2], WorkOrder::query()->orderBy('work_order_no')->pluck('work_order_no')->map(fn ($n) => (int) $n)->all());
    }

    public function test_a_title_with_no_name_gets_a_placeholder_customer_name(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-1', '#44321');

        $this->artisan('crystal-creek:import-jobs')->assertSuccessful();

        $this->assertSame('Crystal Creek Air customer', OutsideCustomer::query()->first()->name);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->makeJob($this->crystalCreek, 'GID-JOB-1', 'Jane Doe - AC not cooling');

        $this->artisan('crystal-creek:import-jobs --dry-run')
            ->expectsOutputToContain('would create')
            ->expectsOutputToContain('1 work order(s) would be created')
            ->assertSuccessful();

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, OutsideCustomer::query()->count());
    }

    public function test_without_the_client_in_the_mirror_it_says_so_and_stops(): void
    {
        config(['services.jobber.crystal_creek_client_gid' => null]);
        $this->crystalCreek->delete();

        $this->artisan('crystal-creek:import-jobs')
            ->expectsOutputToContain('not in jobber_clients yet')
            ->assertSuccessful();
    }
}
