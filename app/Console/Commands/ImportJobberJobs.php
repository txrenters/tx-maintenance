<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Services\JobberOptionalSelections;
use App\Services\JobberTokenService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportJobberJobs extends Command
{
    /**
     * Jobs per page. Each page is a single GraphQL call that also carries the
     * client, property and visits for every job on it, so this is the only
     * request the import makes per page. Kept well under Jobber's 100 maximum
     * because the nested fields multiply the query's cost score.
     */
    private const PAGE_SIZE = 50;

    /**
     * Visits fetched inline per job. A job with more than this many visits is
     * logged rather than silently truncated.
     */
    private const VISITS_PAGE_SIZE = 50;

    private const THROTTLE_ATTEMPTS = 5;

    private const THROTTLE_BACKOFF_SECONDS = 5;

    /**
     * Visit assignees and property coordinates: dropped one at a time for
     * the rest of the run if Jobber's schema rejects them, so one unknown
     * field never sinks the import.
     */
    private JobberOptionalSelections $optional;

    protected $signature = 'jobber:import-jobs';

    protected $description = 'Import jobs from Jobber GraphQL API';

    public function __construct(private JobberTokenService $tokens)
    {
        parent::__construct();

        $this->optional = new JobberOptionalSelections;
    }

    public function handle(): int
    {
        $this->info('Importing jobs from Jobber...');
        Log::info('Importing jobs from Jobber');

        $cursor = null;
        $importedCount = 0;
        $skippedCount = 0;

        do {
            $responseData = $this->getJobs($cursor);

            // Validate response structure
            if (! isset($responseData['data']['jobs']['edges'])) {
                $this->error('Unexpected API response structure');
                Log::error('Unexpected API response structure:', ['response' => $responseData]);

                return self::FAILURE;
            }

            $jobs = $responseData['data']['jobs']['edges'];

            if (! is_array($jobs)) {
                $this->error('Jobs data is not an array');
                Log::error('Jobs data is not an array:', ['jobs' => $jobs]);

                return self::FAILURE;
            }

            if (empty($jobs)) {
                $this->info('No more jobs found to import');
                Log::info('No more jobs found to import');
                break;
            }

            $this->info('Found '.count($jobs).' jobs to import this page');

            foreach ($jobs as $jobEdge) {
                $jobData = $jobEdge['node'] ?? null;

                if (! is_array($jobData)) {
                    $skippedCount++;

                    continue;
                }

                // The client and property ride along on the job node, so a job
                // missing either is a Jobber-side data problem, not a failed
                // call. Skip that one job; the rest of the page still imports.
                $clientData = $jobData['client'] ?? null;
                $propertyData = $jobData['property'] ?? null;

                if (! is_array($clientData) || ! is_array($propertyData)) {
                    $this->warn("Skipping job {$jobData['id']}: missing client or property");
                    Log::warning('Skipping Jobber job with no client or property', [
                        'job_id' => $jobData['id'],
                        'has_client' => is_array($clientData),
                        'has_property' => is_array($propertyData),
                    ]);

                    $skippedCount++;

                    continue;
                }

                $client = $this->createClient($clientData);
                $property = $this->createProperty($propertyData, $client);
                $job = $this->createJob($jobData, $client, $property);

                $visitsData = $jobData['visits']['edges'] ?? null;

                if (is_array($visitsData)) {
                    foreach ($visitsData as $visitEdge) {
                        $visitData = $visitEdge['node'] ?? null;

                        if (is_array($visitData)) {
                            $this->createVisits($visitData, $client, $property, $job);
                        }
                    }

                    if ($jobData['visits']['pageInfo']['hasNextPage'] ?? false) {
                        Log::warning('Job has more visits than one page', [
                            'job_id' => $jobData['id'],
                            'imported' => count($visitsData),
                        ]);
                    }
                }

                $importedCount++;
            }

            // Get next page cursor
            $pageInfo = $responseData['data']['jobs']['pageInfo'] ?? [];
            $cursor = $pageInfo['endCursor'] ?? null;
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;

        } while ($hasNextPage);

        $this->info("Successfully imported {$importedCount} jobs from Jobber ({$skippedCount} skipped)");
        Log::info("Successfully imported {$importedCount} jobs from Jobber", ['skipped' => $skippedCount]);

        return self::SUCCESS;
    }

    public function createClient(array $clientData): object
    {
        $client = JobberClient::updateOrCreate(
            ['jobber_id' => $clientData['id']],
            [
                'first_name' => $clientData['firstName'],
                'last_name' => $clientData['lastName'],
                'company_name' => $clientData['companyName'],
                'name' => $clientData['name'],
                'secondary_name' => $clientData['secondaryName'],
                'title' => $clientData['title'],
                'email' => isset($clientData['emails']) ? json_encode(array_column($clientData['emails'], 'address')) : null,
                'balance' => $clientData['balance'],
                'jobber_web_uri' => $clientData['jobberWebUri'],
            ]
        );

        return $client;
    }

    public function createProperty(array $propertyData, object $client): object
    {
        $attributes = [
            'jobber_client_id' => $client->id,
            'is_billing_address' => $propertyData['isBillingAddress'],
            'street' => $propertyData['address']['street'] ?? null,
            'city' => $propertyData['address']['city'] ?? null,
            'province' => $propertyData['address']['province'] ?? null,
            'postal_code' => $propertyData['address']['postalCode'] ?? null,
            'country' => $propertyData['address']['country'] ?? null,
            'jobber_web_uri' => $propertyData['jobberWebUri'],
        ];

        // Only touch coordinates when the payload carried them — a fallback
        // fetch without the selection must not wipe what an earlier run wrote.
        if (array_key_exists('coordinates', $propertyData['address'] ?? [])) {
            $attributes += JobberProperty::coordinatesFromApi($propertyData);
        }

        return JobberProperty::updateOrCreate(['jobber_id' => $propertyData['id']], $attributes);
    }

    public function createJob(array $jobData, object $client, object $property): object
    {
        $job = Jobber::updateOrCreate(
            ['jobber_id' => $jobData['id']],
            [
                'jobber_client_id' => $client->id,
                'jobber_property_id' => $property->id,
                'job_number' => $jobData['jobNumber'],
                'title' => $jobData['title'],
                'job_status' => $jobData['jobStatus'],
                'job_type' => $jobData['jobType'],
                'total' => $jobData['total'],
                'will_client_be_automatically_charged' => $jobData['willClientBeAutomaticallyCharged'],
                'instructions' => $jobData['instructions'],
                'jobber_web_uri' => $jobData['jobberWebUri'],
                'booking_confirmation_sent_at' => $jobData['bookingConfirmationSentAt'],
                'start_at' => $jobData['startAt'] ? Carbon::parse($jobData['startAt'])->toDateTimeString() : null,
                'end_at' => $jobData['endAt'] ? Carbon::parse($jobData['endAt'])->toDateTimeString() : null,
                'completed_at' => $jobData['completedAt'] ? Carbon::parse($jobData['completedAt'])->toDateTimeString() : null,
                'created_at_jobber' => $jobData['createdAt'] ? Carbon::parse($jobData['createdAt'])->toDateTimeString() : null,
                'updated_at_jobber' => $jobData['updatedAt'] ? Carbon::parse($jobData['updatedAt'])->toDateTimeString() : null,
            ]
        );

        return $job;
    }

    public function createVisits(array $visitData, object $client, object $property, object $job): void
    {
        $attributes = [
            'jobber_id' => $visitData['id'],
            'title' => $visitData['title'],
            'visit_status' => $visitData['visitStatus'],
            'duration' => $visitData['duration'],
            'instructions' => $visitData['instructions'],
            'start_at' => $visitData['startAt'] ? Carbon::parse($visitData['startAt'])->toDateTimeString() : null,
            'end_at' => $visitData['endAt'] ? Carbon::parse($visitData['endAt'])->toDateTimeString() : null,
            'completed_at' => $visitData['completedAt'] ? Carbon::parse($visitData['completedAt'])->toDateTimeString() : null,
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ];

        // Only touch assignees when the payload carried them — a fallback
        // fetch without the selection must not wipe what an earlier run wrote.
        if (array_key_exists('assignedUsers', $visitData)) {
            $attributes['assigned_to'] = JobberVisit::assignedUsersFromApi($visitData);
        }

        JobberVisit::updateOrCreate(['jobber_id' => $visitData['id']], $attributes);
    }

    /**
     * One page of jobs with their client, property and visits nested inline.
     *
     * Fetching those three as separate per-job calls meant 3N round trips for N
     * jobs, which for a full account never finished inside a request. This is
     * the same nested shape JobberWebhookController already uses for a single
     * job, so it is one call per page instead.
     *
     * @return array<string, mixed>|null
     */
    public function getJobs($cursor = null)
    {
        $pageSize = self::PAGE_SIZE;
        $visitsPageSize = self::VISITS_PAGE_SIZE;
        $assignedUsers = $this->optional->fragment(JobberOptionalSelections::ASSIGNED_USERS);
        $coordinates = $this->optional->fragment(JobberOptionalSelections::COORDINATES);

        $query = <<<GRAPHQL
        query (\$cursor: String) {
            jobs(first: {$pageSize}, after: \$cursor) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        jobNumber
                        title
                        jobStatus
                        jobType
                        total
                        willClientBeAutomaticallyCharged
                        instructions
                        jobberWebUri
                        bookingConfirmationSentAt
                        startAt
                        endAt
                        completedAt
                        createdAt
                        updatedAt
                        client {
                            id
                            firstName
                            lastName
                            companyName
                            name
                            secondaryName
                            title
                            balance
                            jobberWebUri
                            emails {
                                address
                            }
                        }
                        property {
                            id
                            isBillingAddress
                            jobberWebUri
                            address {
                                street
                                city
                                province
                                postalCode
                                country
                                {$coordinates}
                            }
                        }
                        visits(first: {$visitsPageSize}) {
                            pageInfo {
                                hasNextPage
                            }
                            edges {
                                node {
                                    id
                                    title
                                    visitStatus
                                    duration
                                    instructions
                                    startAt
                                    endAt
                                    completedAt
                                    {$assignedUsers}
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $response = $this->postWithThrottleRetry([
            'query' => $query,
            'variables' => ['cursor' => $cursor],
        ]);

        // Schema safety net: if this Jobber API version rejects one of the
        // optional selections, drop it for the rest of the run and ask again
        // instead of failing the whole import.
        $rejected = $this->optional->rejectedBy($response);

        if ($rejected !== null) {
            Log::warning("Jobber rejected the {$rejected} selection; importing without it.");

            return $this->getJobs($cursor);
        }

        return $response;
    }

    /**
     * POST a GraphQL body, backing off and retrying while Jobber reports the
     * request as throttled. Nesting three sub-selections raises the query's
     * cost, so a large import can outrun the rate limiter and must wait rather
     * than treat the throttle as a hard failure.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function postWithThrottleRetry(array $body): ?array
    {
        for ($attempt = 1; $attempt <= self::THROTTLE_ATTEMPTS; $attempt++) {
            $response = $this->tokens->graphql($body);

            if ($response->failed()) {
                $this->error('Failed to fetch jobs: '.$response->body());
                Log::error('Failed to fetch jobs:', ['response' => $response->body()]);

                return null;
            }

            $json = $response->json();

            if (! $this->isThrottled($json)) {
                return $json;
            }

            $this->warn("Jobber throttled the request, waiting (attempt {$attempt})");
            Log::warning('Jobber throttled the jobs query', ['attempt' => $attempt]);

            sleep(self::THROTTLE_BACKOFF_SECONDS * $attempt);
        }

        Log::error('Gave up on the Jobber jobs query after repeated throttling');

        return null;
    }

    /**
     * Jobber reports a rate limit as HTTP 200 with a THROTTLED error code.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function isThrottled(?array $json): bool
    {
        foreach ($json['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }
}
