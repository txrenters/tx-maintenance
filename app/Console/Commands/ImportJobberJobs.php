<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberToken;
use App\Models\JobberVisit;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportJobberJobs extends Command
{
    protected $signature = 'jobber:import-jobs';
    protected $description = 'Import jobs from Jobber GraphQL API';

    public function handle()
    {
        $this->info('Importing jobs from Jobber...');
        Log::info('Importing jobs from Jobber');

        $responseData = $this->getJobs();
        
        // Check if we have the expected data structure
        if (!isset($responseData['data']['jobs']['edges'])) {
            $this->error('Unexpected API response structure');
            Log::error('Unexpected API response structure:', ['response' => $responseData]);
            return;
        }

        $jobs = $responseData['data']['jobs']['edges'];
        
        // Check if jobs is actually an array
        if (!is_array($jobs)) {
            $this->error('Jobs data is not an array');
            Log::error('Jobs data is not an array:', ['jobs' => $jobs]);
            return;
        }

        if (empty($jobs)) {
            $this->info('No jobs found to import');
            Log::info('No jobs found to import');
            return;
        }

        $this->info('Found ' . count($jobs) . ' jobs to import');
        $importedCount = 0;

        foreach($jobs as $jobEdge) {
            $jobData = $jobEdge['node'];

            $client= $this->getClient($jobData['id']);
            $clientData = $client['data']['job']['client'];
            
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
                    'jobber_web_uri' => $clientData['jobberWebUri']
                ]
            );

            $property = $this->getProperty($jobData['id']);
            $propertyData = $property['data']['job']['property'];
            // Create/update property
            $property = JobberProperty::updateOrCreate(
                ['jobber_id' => $propertyData['id']],
                [
                    'jobber_client_id' => $client->id,
                    'is_billing_address' => $propertyData['isBillingAddress'],
                    'street' => $propertyData['address']['street'] ?? null,
                    'city' => $propertyData['address']['city'] ?? null,
                    'province' => $propertyData['address']['province'] ?? null,
                    'postal_code' => $propertyData['address']['postalCode'] ?? null,
                    'country' => $propertyData['address']['country'] ?? null,
                    'jobber_web_uri' => $propertyData['jobberWebUri']
                ]
            );  

            // Create/update job (INSIDE the loop)
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
                    'updated_at_jobber' => $jobData['updatedAt'] ? Carbon::parse($jobData['updatedAt'])->toDateTimeString() : null
                ]
            );

            $visits = $this->getVisits($jobData['id']);
            $visitsData = $visits['data']['job']['visits']['edges'];

            // Create/update visits for this job
            if (isset($visitsData) && is_array($visitsData)) {
                foreach ($visitsData as $visitEdge) {
                    $visitData = $visitEdge['node'];
                    JobberVisit::updateOrCreate(
                        ['jobber_id' => $visitData['id']],
                        [
                            'jobber_id' => $visitData['id'],
                            'title' => $visitData['title'],
                            'visit_status' => $visitData['visitStatus'],
                            'duration' => $visitData['duration'],
                            'instructions' => $visitData['instructions'],
                            'start_at' => $visitData['startAt'] ? Carbon::parse($visitData['startAt'])->toDateTimeString() : null ,
                            'end_at' =>  $visitData['endAt'] ? Carbon::parse( $visitData['endAt'])->toDateTimeString() : null,
                            'completed_at' => $visitData['completedAt'] ? Carbon::parse( $visitData['completedAt'])->toDateTimeString() : null,
                            'jobber_job_id' => $job->id,
                            'jobber_client_id' => $client->id,
                            'jobber_property_id' => $property->id,
                        ]
                    );
                }
            }

            $importedCount++;
        }

        $this->info("Successfully imported {$importedCount} jobs from Jobber");
        Log::info("Successfully imported {$importedCount} jobs from Jobber");
    }

    public function getJobs(){
        $headers = $this->accessToken();

        $query = 'query {
            jobs(first: 500) {
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
                    }
                }
            }
        }';
        $response = Http::withHeaders($headers)
             ->timeout(60)
            ->retry(3, 2000)  // Increase timeout to 30 seconds
            ->post('https://api.getjobber.com/api/graphql', [
                'query' => $query
            ]);

        if ($response->failed()) {
            $this->error('Failed to fetch jobs: ' . $response->body());
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);
            return;
        }

        // Debug the response structure
        return $response->json();
    }

    public function getClient($jobberId){

        $headers = $this->accessToken();

        $query = 'query {
                job(id: "'.$jobberId.'") {
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
                }
            }';

        $response = Http::withHeaders($headers)
             ->timeout(60)
    ->retry(3, 2000)  // Increase timeout to 30 seconds
            ->post('https://api.getjobber.com/api/graphql', [
                'query' => $query
            ]);

        if ($response->failed()) {
            $this->error('Failed to fetch jobs: ' . $response->body());
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);
            return;
        }

        Log::info('Client:', ['response' => $response->json()]);

        // Debug the response structure
        return $response->json();
    }

    public function getProperty($jobberId){

        $headers = $this->accessToken();

        $query = 'query {
                job(id: "'.$jobberId.'") {
                    property{
                        id
                        isBillingAddress
                        jobberWebUri
                        address {
                            street
                            city
                            province
                            postalCode
                            country
                        }
                    }
                }
            }';

        $response = Http::withHeaders($headers)
             ->timeout(60)
    ->retry(3, 2000)  // Increase timeout to 30 seconds
            ->post('https://api.getjobber.com/api/graphql', [
                'query' => $query
            ]);

        if ($response->failed()) {
            $this->error('Failed to fetch jobs: ' . $response->body());
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);
            return;
        }

        Log::info('Property:', ['response' => $response->json()]);

        // Debug the response structure
        return $response->json();
    }

    public function getVisits($jobberId){

        $headers = $this->accessToken();

        $query = 'query {
                job(id: "'.$jobberId.'") {
                    visits {
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
                            }
                        }
                    }
                }
            }';

        $response = Http::withHeaders($headers)
             ->timeout(60)
    ->retry(3, 2000)  // Increase timeout to 30 seconds
            ->post('https://api.getjobber.com/api/graphql', [
                'query' => $query
            ]);

        if ($response->failed()) {
            $this->error('Failed to fetch jobs: ' . $response->body());
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);
            return;
        }

        Log::info('Visits:', ['response' => $response->json()]);

        // Debug the response structure
        return $response->json();
    }

    public function accessToken(){
        $jobberToken = JobberToken::whereNotNull('access_token')->first();
        $accessToken = [
            'Authorization' => 'Bearer '.$jobberToken->access_token,
            'X-JOBBER-GRAPHQL-VERSION' => env('JOBBER_API_VERSION'),
            'Content-Type' => 'application/json',
        ];

        return $accessToken;
    }
}