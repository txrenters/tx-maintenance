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
     * @return array<string, mixed>
     */
    private function jobNode(string $id, int $jobNumber): array
    {
        return [
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
        ];
    }

    /**
     * Jobber answers every GraphQL call on the same URL, so the fake is driven
     * by the query text in the request body.
     */
    private function fakeJobber(?array $visitsResponse = null): void
    {
        $visitsResponse ??= [
            'data' => [
                'job' => [
                    'visits' => [
                        'edges' => [
                            ['node' => [
                                'id' => 'visit-1',
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
                ],
            ],
        ];

        Http::fake([
            'api.getjobber.com/api/graphql' => function ($request) use ($visitsResponse) {
                $query = $request->data()['query'];

                if (str_contains($query, 'visits')) {
                    return Http::response($visitsResponse);
                }

                if (str_contains($query, 'firstName')) {
                    return Http::response(['data' => ['job' => ['client' => [
                        'id' => 'client-1',
                        'firstName' => 'Pat',
                        'lastName' => 'Owner',
                        'companyName' => null,
                        'name' => 'Pat Owner',
                        'secondaryName' => null,
                        'title' => null,
                        'balance' => 0,
                        'jobberWebUri' => 'https://secure.getjobber.com/clients/1',
                        'emails' => [],
                    ]]]]);
                }

                if (str_contains($query, 'isBillingAddress')) {
                    return Http::response(['data' => ['job' => ['property' => [
                        'id' => 'property-1',
                        'isBillingAddress' => false,
                        'jobberWebUri' => 'https://secure.getjobber.com/properties/1',
                        'address' => [
                            'street' => '123 Main St',
                            'city' => 'Austin',
                            'province' => 'TX',
                            'postalCode' => '78701',
                            'country' => 'USA',
                        ],
                    ]]]]);
                }

                return Http::response($this->jobsPage());
            },
        ]);
    }

    public function test_the_visits_query_is_valid_graphql(): void
    {
        $this->fakeJobber();

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        // The stray characters that used to sit in this query made Jobber reject
        // it, which took the whole import down with it.
        Http::assertSent(function ($request) {
            $query = $request->data()['query'];

            return str_contains($query, 'visits {') && ! str_contains($query, '..');
        });
    }

    public function test_every_job_on_the_page_is_imported_with_its_visits(): void
    {
        $this->fakeJobber();

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(2, Jobber::query()->count());
        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
        $this->assertSame(1, JobberVisit::query()->count());
    }

    public function test_a_graphql_error_on_visits_does_not_abort_the_whole_import(): void
    {
        // A GraphQL error comes back as HTTP 200 with no "data" key at all.
        $this->fakeJobber(visitsResponse: [
            'errors' => [['message' => 'Parse error on "..." (error) at [8, 40]']],
        ]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        // Both jobs still land; only their visits are missing.
        $this->assertSame(2, Jobber::query()->count());
        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
        $this->assertSame(0, JobberVisit::query()->count());
    }

    public function test_a_job_with_no_client_data_is_skipped_and_the_rest_still_import(): void
    {
        Http::fake([
            'api.getjobber.com/api/graphql' => function ($request) {
                $query = $request->data()['query'];

                if (str_contains($query, 'visits')) {
                    return Http::response(['data' => ['job' => ['visits' => ['edges' => []]]]]);
                }

                if (str_contains($query, 'firstName')) {
                    // First job errors, second job resolves.
                    return str_contains($query, 'job-1')
                        ? Http::response(['errors' => [['message' => 'Not found']]])
                        : Http::response(['data' => ['job' => ['client' => [
                            'id' => 'client-1',
                            'firstName' => 'Pat',
                            'lastName' => 'Owner',
                            'companyName' => null,
                            'name' => 'Pat Owner',
                            'secondaryName' => null,
                            'title' => null,
                            'balance' => 0,
                            'jobberWebUri' => 'https://secure.getjobber.com/clients/1',
                            'emails' => [],
                        ]]]]);
                }

                if (str_contains($query, 'isBillingAddress')) {
                    return Http::response(['data' => ['job' => ['property' => [
                        'id' => 'property-1',
                        'isBillingAddress' => false,
                        'jobberWebUri' => 'https://secure.getjobber.com/properties/1',
                        'address' => [
                            'street' => '123 Main St',
                            'city' => 'Austin',
                            'province' => 'TX',
                            'postalCode' => '78701',
                            'country' => 'USA',
                        ],
                    ]]]]);
                }

                return Http::response($this->jobsPage());
            },
        ]);

        $this->artisan('jobber:import-jobs')->assertSuccessful();

        $this->assertSame(1, Jobber::query()->count());
        $this->assertTrue(Jobber::query()->where('job_number', 19485)->exists());
    }

    public function test_a_malformed_jobs_response_reports_a_failing_exit_code(): void
    {
        Http::fake([
            'api.getjobber.com/api/graphql' => Http::response([
                'errors' => [['message' => 'Something went wrong']],
            ]),
        ]);

        $this->artisan('jobber:import-jobs')->assertFailed();
    }
}