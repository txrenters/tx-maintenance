<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobberWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $clientSecret = env('JOBBER_SECRET');
        $hmacHeader = $request->header('X-Jobber-Hmac-SHA256');
        $rawPayload = $request->getContent();

        $calculatedHmac = base64_encode(hash_hmac('sha256', $rawPayload, $clientSecret, true));

        // Securely compare the hashes to prevent timing attacks
        if (! hash_equals($calculatedHmac, $hmacHeader)) {
            Log::warning('Jobber webhook signature mismatch.');
            abort(401, 'Invalid signature');
        }

        try {
            $payload = $request->input('data.webHookEvent');
            Log::info('Jobber webhook received', $payload);

            $topic = $payload['topic'];
            $itemId = $payload['itemId']; // Base64-encoded

            match ($topic) {
                'JOB_CREATE',
                'JOB_UPDATE' => $this->handleupdateOrCreateJobber($itemId),
                'JOB_CLOSED' => $this->handleClosedJobber($itemId),
                'JOB_DESTROY' => $this->handleDeleteJobber($itemId),
                'VISIT_CREATE',
                'VISIT_UPDATE' => $this->handleCreateOrUpdateVisit($itemId),
                'VISIT_COMPLETE' => $this->handleCompleteVisit($itemId),
                'VISIT_DESTROY' => $this->handleDeleteVisit($itemId),
                default => Log::warning("Unhandled webhook topic: $topic"),
            };

            return response()->json(['status' => 'ok']);

        } catch (\Throwable $e) {
            Log::error('Jobber Webhook Error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function handleupdateOrCreateJobber($jobberId)
    {
        $responseData = $this->getJobDetails($jobberId);

        $job = $responseData['data']['job'];

        $clientData = $job['client'];

        $client = $this->updateOrCreateClient($clientData);

        $propertyData = $job['property'];

        $property = $this->updateOrCreateProperty($propertyData, $client);

        $jobModel = $this->updateOrCreateJob($job, $client, $property);

        $visitsData = $job['visits']['edges'] ?? [];

        if (isset($visitsData) && is_array($visitsData)) {
            foreach ($visitsData as $visitEdge) {
                $visitData = $visitEdge['node'];
                $this->updateOrCreateVisit($visitData, $client, $property, $jobModel);
            }
        }

        Log::info('Job has been updated successfully:', ['job' => $jobModel]);
    }

    public function handleDeleteJobber($jobberId): void
    {
        $job = Jobber::where('jobber_id', $jobberId)->first();

        if (! $job) {
            Log::warning('Job not found when trying to delete.', ['jobber_id' => $jobberId]);

            return;
        }

        $jobId = $job->id;

        $job->delete();

        Log::info('Job has been deleted:', ['job' => $job]);
    }

    public function handleClosedJobber($jobberId): void
    {
        $job = Jobber::where('jobber_id', $jobberId)->first();

        if (! $job) {
            Log::warning('Job not found when trying to closed.', ['jobber_id' => $jobberId]);

            return;
        }

        $job->update([
            'job_status' => 'closed',
        ]);
        Log::info('Job has been closed:', ['job' => $job]);
    }

    public function handleCreateOrUpdateVisit($visitId)
    {
        $responseData = $this->getVisitDetails($visitId);

        if (! isset($responseData['data']['visit'])) {
            Log::error('Visit not found from Jobber API', ['visitId' => $visitId]);

            return;
        }

        $jobberVisit = $responseData['data']['visit'];

        // Find the existing visit to get related job, client, and property
        $existingVisit = JobberVisit::with(['job.client', 'job.property'])
            ->where('jobber_id', $jobberVisit['id'])
            ->first();

        // If visit exists with job, update it
        if ($existingVisit && $existingVisit->job) {
            $this->updateOrCreateVisit($jobberVisit, $existingVisit->job->client, $existingVisit->job->property, $existingVisit->job);
            Log::info('Visit updated successfully:', ['visit' => $jobberVisit]);
        } else {
            $jobberClient = $jobberVisit['client'];

            $clientData = [
                'id' => $jobberClient['id'],
                'firstName' => $jobberClient['firstName'],
                'lastName' => $jobberClient['lastName'],
                'companyName' => $jobberClient['companyName'],
                'name' => $jobberClient['name'],
                'secondaryName' => $jobberClient['secondaryName'],
                'title' => $jobberClient['title'],
                'email' => isset($jobberClient['emails']) ? json_encode(array_column($jobberClient['emails'], 'address')) : null,
                'balance' => $jobberClient['balance'],
                'jobberWebUri' => $jobberClient['jobberWebUri'],
            ];

            $client = $this->updateOrCreateClient($clientData);

            $jobberProperty = $jobberVisit['property'];

            $propertyData = [
                'id' => $jobberProperty['id'],
                'isBillingAddress' => $jobberProperty['isBillingAddress'],
                'address' => [
                    'street' => $jobberProperty['address']['street'] ?? null,
                    'city' => $jobberProperty['address']['city'] ?? null,
                    'province' => $jobberProperty['address']['province'] ?? null,
                    'postalCode' => $jobberProperty['address']['postalCode'] ?? null,
                    'country' => $jobberProperty['address']['country'] ?? null,
                ],
                'jobberWebUri' => $jobberProperty['jobberWebUri'],
            ];

            $property = $this->updateOrCreateProperty($propertyData, $client);

            $this->updateOrCreateVisit($jobberVisit, $client, $property, $existingVisit->job);
            Log::warning('Visit created successfully: ', ['visitId' => $jobberVisit]);
        }

    }

    public function handleCompleteVisit($visitId): void
    {
        $visit = JobberVisit::where('jobber_id', $visitId)->first();

        if (! $visit) {
            Log::warning('Visit not found when trying to complete.', ['visit_id' => $visitId]);

            return;
        }

        $visit->update([
            'completed_at' => now(),
            'visit_status' => 'completed',
        ]);
        Log::info('Visit completed successfully:', ['visit' => $visit]);
        // Check if all visits for this job are completed
        $job = $visit->job;

        if ($job) {
            $allVisitsCompleted = $job->visits()->whereNull('completed_at')->count() === 0;

            if ($allVisitsCompleted) {
                $job->update([
                    'completed_at' => now(),
                ]);

                Log::info('Job completed successfully:', ['job' => $job]);
            }
        }

        Log::info('Visit has been completed:', [
            'visit_id' => $visitId,
            'job_id' => $job ? $job->id : null,
            'completed_at' => $visit->completed_at,
        ]);
    }

    public function handleupdateOrCreateVisit($visitId)
    {
        $responseData = $this->getVisitDetails($visitId);

        if (! isset($responseData['data']['visit'])) {
            Log::error('Visit not found from Jobber API', ['visitId' => $visitId]);

            return;
        }

        $jobberVisit = $responseData['data']['visit'];

        // Find the existing visit to get related job, client, and property
        $existingVisit = JobberVisit::with(['job.client', 'job.property'])
            ->where('jobber_id', $jobberVisit['id'])
            ->first();

        // If visit exists with job, update it
        if ($existingVisit && $existingVisit->job) {
            $this->updateVisit($jobberVisit, $existingVisit->job->client, $existingVisit->job->property, $existingVisit->job);
            Log::info('Visit updated successfully:', ['visit' => $jobberVisit]);
        } else {
            $this->createVisit($jobberVisit, $existingVisit->job->client, $existingVisit->job->property, $existingVisit->job);
            Log::warning('Visit created successfully: ', ['visitId' => $jobberVisit]);
        }

    }

    public function handleDeleteVisit($visitId)
    {
        $visit = JobberVisit::where('jobber_id', $visitId)->first();

        if ($visit) {
            $visitId = $visit->id;
            $visit->delete();

            Log::info('Visit deleted', ['jobber_id' => $visitId]);
        } else {
            Log::warning('Visit not found when trying to delete', ['jobber_id' => $visitId]);
        }
    }

    public function getJobDetails($jobberId)
    {
        $headers = $this->accessTokenHeaders();

        $query = 'query {
                job(id: "'.$jobberId.'") {
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

        try {
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->retry(3, 2000)
                ->post('https://api.getjobber.com/api/graphql', [
                    'query' => $query,
                ]);

        } catch (RequestException $e) {
            if ($e->response && $e->response->status() === 401) {
                Log::warning('Access token expired. Refreshing token...');

                $jobberAuth = new JobberAuthController;
                $jobberAuth->refreshAccessToken();

                // Get fresh headers with the new access token
                $headers = $this->accessTokenHeaders();

                // Use the new access token after refresh
                $response = Http::withHeaders($headers)
                    ->timeout(60)
                    ->retry(3, 2000)
                    ->post('https://api.getjobber.com/api/graphql', [
                        'query' => $query,
                    ]);
            } else {
                Log::error('Failed to refresh the token:', ['response' => $e->response]);
                throw $e; // re-throw if it's not a 401
            }
        }

        if ($response->failed()) {
            Log::error('Failed to fetch job details:', [
                'jobberId' => $jobberId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json(['error' => 'Failed to fetch job details'], 500);
        }

        return $response->json();
    }

    public function getVisitDetails($jobberId)
    {
        $headers = $this->accessTokenHeaders();

        $query = 'query {
                visit(id: "'.$jobberId.'") {
                    id
                    title
                    visitStatus
                    duration
                    instructions
                    startAt
                    endAt
                    completedAt
                    job{
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
                    client{
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

        try {
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->retry(3, 2000)
                ->post('https://api.getjobber.com/api/graphql', [
                    'query' => $query,
                ]);

        } catch (RequestException $e) {
            if ($e->response && $e->response->status() === 401) {
                Log::warning('Access token expired. Refreshing token...');

                $jobberAuth = new JobberAuthController;
                $jobberAuth->refreshAccessToken();

                // Get fresh headers with the new access token
                $headers = $this->accessTokenHeaders();

                // Use the new access token after refresh
                $response = Http::withHeaders($headers)
                    ->timeout(60)
                    ->retry(3, 2000)
                    ->post('https://api.getjobber.com/api/graphql', [
                        'query' => $query,
                    ]);
            } else {
                Log::error('Failed to refresh the token:', ['response' => $e->response]);
                throw $e; // re-throw if it's not a 401
            }
        }

        if ($response->failed()) {
            Log::error('Failed to fetch jobs:', ['response' => $response->body()]);

            return;
        }

        return $response->json();
    }

    public function updateOrCreateClient(array $clientData): object
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

    public function updateOrCreateProperty(array $propertyData, object $client): object
    {
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
                'jobber_web_uri' => $propertyData['jobberWebUri'],
            ]
        );

        return $property;
    }

    public function updateOrCreateJob(array $jobData, object $client, object $property): object
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

    public function updateOrCreateVisit(array $visitData, object $client, object $property, object $job): void
    {
        JobberVisit::updateOrCreate(
            ['jobber_id' => $visitData['id']],
            [
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
            ]
        );
    }

    public function accessTokenHeaders()
    {
        try {
            $jobberAuth = new JobberAuthController;
            $accessToken = $jobberAuth->ensureValidToken();

            return [
                'Authorization' => 'Bearer '.$accessToken,
                'X-JOBBER-GRAPHQL-VERSION' => env('JOBBER_API_VERSION'),
                'Content-Type' => 'application/json',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get valid Jobber token', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
