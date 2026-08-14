<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberToken;
use App\Models\JobberVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobberImportJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        JobberToken::query()->create([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * Two jobs on one page, so a failure on the first job is visible as a
     * missing second job rather than as a silently short import.
     *
     * @return array<string, mixed>
     */
    private function jobsPage(): array
    {
        return [
            'data' => [
                'jobs' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => $this->jobNode('job-1', 19484)],
                        ['node' => $this->jobNode('job-2', 19485)],
                    ],
                ],
            ],
        ];
    }

    /**
     * A job node carrying its client, property and visits inline, the way the
     * real nested query returns them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function jobNode(string $id, int $jobNumber, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'jobNumber' => $jobNumber,
            'title' => "Job {$jobNumber}",
            'jobStatus' => 'active',
            'jobType' => 'ONE_OFF',
            'total' => 0,
            'willClientBeAutomaticallyCharged' => false,
            'instructions' => null,
            'jobberWebUri' => "https://secure.getjobber.com/jobs/{$jobNumber}",
            'bookingConfirmationSentAt' => null,
            'startAt' => null,
            'endAt' => null,
            'completedAt' => null,
            'createdAt' => null,
            'updatedAt' => null,
            'client' => [
                'id' => "client-{$id}",
                'firstName' => 'Pat',
                'lastName' => 'Owner',
                'companyName' => null,
                'name' => 'Pat Owner',
                'secondaryName' => null,
                'title' => null,
                'balance' => 0,
                'jobberWebUri' => 'https://secure.getjobber.com/clients/1',
                'emails' => [],
            ],
            'property' => [
                'id' => "property-{$id}",
                'isBillingAddress' => false,
                'jobberWebUri' => 'https://secure.getjobber.com/properties/1',
                'address' => [
                    'street' => '123 Main St',
                    'city' => 'Austin',
                    'province' => 'TX',
                    'postalCode' => '78701',
                    'country' => 'USA',
                ],
            ],
            'visits' => [
                'pageInfo' => ['hasNextPage' => false],
                'edges' => [
                    ['node' => [
                        'id' => "visit-{$id}",
                        'title' => 'Visit',
                        'visitStatus' => 'UNSCHEDULED',
                        'duration' => 0,
                        'instructions' => null,
                        'startAt' => null,
                        'endAt' => null,
                        'completedAt' => null,
                    ]],
                ],
            ],
        ], $overrides);
    }

    public function test_a_page_of_jobs_costs_exactly_one_request(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response($this->jobsPage())]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        // The import used to make three extra calls per job, which for a full
        // account was tens of thousands of round trips and never finished.
        Http::assertSentCount(1);
    }

    public function test_the_page_query_asks_for_client_property_and_visits_inline(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response($this->jobsPage())]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        Http::assertSent(function ($request) {
            $query = $request->data()['query'];

            return str_contains($query, 'jobs(first:')
                && str_contains($query, 'client {')
                && str_contains($query, 'property {')
                && str_contains($query, 'visits(first:')
                // The stray characters that once sat in the visits query made
                // Jobber reject it outright.
                && ! str_contains($query, '..');
        });
    }

    public function test_every_job_on_the_page_is_imported_with_its_visits(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response($this->jobsPage())]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(2, Jobber::query()->count());
        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
        $this->assertSame(2, JobberVisit::query()->count());
    }

    public function test_a_job_missing_its_client_is_skipped_and_the_rest_still_import(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response([
            'data' => [
                'jobs' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => $this->jobNode('job-1', 19484, ['client' => null])],
                        ['node' => $this->jobNode('job-2', 19485)],
                    ],
                ],
            ],
        ])]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(1, Jobber::query()->count());
        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
    }

    public function test_a_job_with_no_visits_still_imports(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response([
            'data' => [
                'jobs' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => $this->jobNode('job-1', 19485, ['visits' => null])],
                    ],
                ],
            ],
        ])]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
        $this->assertSame(0, JobberVisit::query()->count());
    }

    public function test_every_page_is_walked_until_the_cursor_runs_out(): void
    {
        Http::fakeSequence('api.getjobber.com/api/graphql')
            ->push([
                'data' => [
                    'jobs' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'],
                        'edges' => [['node' => $this->jobNode('job-1', 19484)]],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'jobs' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [['node' => $this->jobNode('job-2', 19485)]],
                    ],
                ],
            ]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(2, Jobber::query()->count());

        // The second page must be requested with the cursor the first returned.
        Http::assertSent(fn ($request) => ($request->data()['variables']['cursor'] ?? null) === 'page-2');
    }

    public function test_a_malformed_jobs_response_reports_a_failing_exit_code(): void
    {
        Http::fake(['api.getjobber.com/api/graphql' => Http::response([
            'errors' => [['message' => 'Something went wrong']],
        ])]);

        $this->artisan('jobber:import-jobs')->assertFailed();
    }

    public function test_a_throttled_response_is_retried_rather_than_treated_as_a_failure(): void
    {
        Http::fakeSequence('api.getjobber.com/api/graphql')
            ->push(['errors' => [[
                'message' => 'Throttled',
                'extensions' => ['code' => 'THROTTLED'],
            ]]])
            ->push($this->jobsPage());

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(2, Jobber::query()->count());
        Http::assertSentCount(2);
    }
}
