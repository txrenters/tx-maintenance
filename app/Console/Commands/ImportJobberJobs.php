<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
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

        $query = 'query {
            jobs(first: 10) {
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
                            emails {
                                address
                            }
                            balance
                            jobberWebUri
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
                            }
                        }
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
                }
            }
        }';

        $accessToken = [
            'Authorization' => 'Bearer '.env('JOBBER_API_TOKEN'),
            'X-JOBBER-GRAPHQL-VERSION' => env('JOBBER_API_VERSION'),
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($accessToken)
            ->post('https://api.getjobber.com/api/graphql', [
                'query' => $query
            ]);

        if ($response->failed()) {
            $this->error('Failed to fetch jobs: ' . $response->body());
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);
            return;
        }

        // Debug the response structure
        $responseData = $response->json();
        Log::info('Jobber API Response:', ['response' => $responseData]);
        
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
            $propertyData = $jobData['property'];
            $clientData = $jobData['client'];

            // Create/update client with CORRECT field mapping
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

            // Create/update visits for this job
            if (isset($jobData['visits']['edges']) && is_array($jobData['visits']['edges'])) {
                foreach ($jobData['visits']['edges'] as $visitEdge) {
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
}