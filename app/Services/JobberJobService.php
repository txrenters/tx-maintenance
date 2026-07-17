<?php

namespace App\Services;

use App\Models\JobberToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creates the Jobber job for a THMP work order and returns its id + deep link.
 *
 * This ports the previous n8n "Create Job" workflow into the codebase: search
 * Jobber for the property (matched on the first two words of the client name vs
 * the building name, exactly as n8n did), then run the jobCreate mutation.
 * Because we make the call ourselves, the response gives us the new job's id and
 * jobberWebUri directly — no round-trip and no address re-matching.
 */
class JobberJobService
{
    /**
     * Find the Jobber property for a building and create the job for the work
     * order. Returns the created job's global id and web URI.
     *
     * @return array{gid: string, web_uri: string}|null Null when no property
     *                                                  matches or Jobber rejects the mutation.
     */
    public function createJobForWorkOrder(WorkOrder $workOrder): ?array
    {
        $buildingName = (string) ($workOrder->building?->name ?? '');

        if ($buildingName === '') {
            Log::warning('Jobber job: work order has no building name.', ['work_order_id' => $workOrder->id]);

            return null;
        }

        $propertyId = $this->findPropertyId($buildingName);

        if ($propertyId === null) {
            Log::warning('Jobber job: no matching Jobber property found.', [
                'work_order_id' => $workOrder->id,
                'building' => $buildingName,
            ]);

            return null;
        }

        return $this->createJob($workOrder, $propertyId);
    }

    /**
     * Search Jobber properties by building name and return the id of the first
     * one whose client name matches (first two words, lowercased, n8n's rule).
     */
    private function findPropertyId(string $buildingName): ?string
    {
        $query = 'query ($term: String!) {
            properties(first: 10, searchTerm: $term) {
                edges { node { id client { name } } }
            }
        }';

        $response = $this->post([
            'query' => $query,
            'variables' => ['term' => $buildingName],
        ]);

        if ($response === null) {
            return null;
        }

        $edges = data_get($response, 'data.properties.edges', []);
        $buildingKey = $this->firstTwoWords($buildingName);

        foreach ($edges as $edge) {
            $clientName = (string) data_get($edge, 'node.client.name', '');

            if ($this->matchesBuilding($clientName, $buildingKey)) {
                return data_get($edge, 'node.id');
            }
        }

        return null;
    }

    /**
     * Run the jobCreate mutation. Job values are passed as GraphQL variables so
     * arbitrary description/tenant text can never break the query.
     *
     * @return array{gid: string, web_uri: string}|null
     */
    private function createJob(WorkOrder $workOrder, string $propertyId): ?array
    {
        $mutation = 'mutation ($input: JobCreateAttributes!) {
            jobCreate(input: $input) {
                job { id jobberWebUri }
                userErrors { message }
            }
        }';

        $input = [
            'propertyId' => $propertyId,
            'title' => $this->jobTitle($workOrder),
            'instructions' => $this->jobInstructions($workOrder),
            'invoicing' => [
                'invoicingType' => 'FIXED_PRICE',
                'invoicingSchedule' => 'ON_COMPLETION',
            ],
            'scheduling' => [
                'createVisits' => true,
                'notifyTeam' => true,
                'assignedTo' => [config('services.jobber.thmp_assignee_gid')],
                'visitConfirmationStatus' => false,
            ],
        ];

        $response = $this->post([
            'query' => $mutation,
            'variables' => ['input' => $input],
        ]);

        if ($response === null) {
            return null;
        }

        $userErrors = data_get($response, 'data.jobCreate.userErrors', []);

        if (! empty($userErrors)) {
            Log::error('Jobber job: jobCreate returned userErrors.', [
                'work_order_id' => $workOrder->id,
                'errors' => $userErrors,
            ]);

            return null;
        }

        $gid = data_get($response, 'data.jobCreate.job.id');
        $webUri = data_get($response, 'data.jobCreate.job.jobberWebUri');

        if (blank($gid)) {
            Log::error('Jobber job: jobCreate returned no job id.', [
                'work_order_id' => $workOrder->id,
                'response' => $response,
            ]);

            return null;
        }

        return ['gid' => (string) $gid, 'web_uri' => (string) $webUri];
    }

    private function jobTitle(WorkOrder $workOrder): string
    {
        $building = (string) ($workOrder->building?->name ?? '');
        $zone = (string) ($workOrder->zone ?? '');
        $category = (string) ($workOrder->category ?? '');

        return "{$building} - Zone {$zone} - {$category} - #{$workOrder->work_order_no}";
    }

    /**
     * Description followed by the tenant's name and mobile, matching the n8n
     * instructions (which appended report columns 4 and 5 — Full Name and
     * Mobile Phone #). Sourced from our own tenant record.
     */
    private function jobInstructions(WorkOrder $workOrder): string
    {
        $description = trim(preg_replace('/\s+/', ' ', (string) $workOrder->description));

        $tenant = $workOrder->requested_by;
        $name = trim(($tenant->first_name ?? '').' '.($tenant->last_name ?? ''));
        $mobile = trim((string) ($tenant->mobile_phone ?? ''));

        return trim("{$description} - {$name} - {$mobile}", ' -');
    }

    private function firstTwoWords(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        return implode(' ', array_slice(array_filter(explode(' ', $value)), 0, 2));
    }

    private function matchesBuilding(string $clientName, string $buildingKey): bool
    {
        if ($buildingKey === '') {
            return false;
        }

        return str_contains($this->firstTwoWords($clientName), $buildingKey);
    }

    /**
     * POST a GraphQL body to Jobber with the stored bearer token. Returns the
     * decoded JSON, or null on a failed request (logged) so callers degrade
     * gracefully instead of throwing inside a queued job.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(array $body): ?array
    {
        $token = JobberToken::first();

        if (! $token || blank($token->access_token)) {
            Log::error('Jobber job: no Jobber access token available.');

            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token->access_token,
            'X-JOBBER-GRAPHQL-VERSION' => config('services.jobber.api_version'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->retry(3, 2000)
            ->post(config('services.jobber.graphql_url'), $body);

        if ($response->failed()) {
            Log::error('Jobber job: GraphQL request failed.', ['body' => $response->body()]);

            return null;
        }

        return $response->json();
    }
}
