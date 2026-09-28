<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
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
    /** The zones THMP works in, as PropertyWare's Zone field spells them. */
    private const ZONES = ['1', '2', '3', '4', '5'];

    public function __construct(private JobberTokenService $tokens) {}

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

    /**
     * "{building} - Zone N - {category} - #WO". The zone segment is dropped
     * when no real zone is known, so a title never reads "Zone 0".
     */
    private function jobTitle(WorkOrder $workOrder): string
    {
        $building = (string) ($workOrder->building?->name ?? '');
        $category = (string) ($workOrder->category ?? '');
        $zone = $this->zoneForTitle($workOrder);
        $zoneSegment = $zone === null ? '' : "Zone {$zone} - ";

        return "{$building} - {$zoneSegment}{$category} - #{$workOrder->work_order_no}";
    }

    /**
     * The zone the title should carry: the work order's own when PropertyWare
     * has a real one, otherwise the zone the building's other work orders
     * carry most often (ties go to the newest). Null when neither says.
     *
     * PropertyWare's Zone custom field is a Number, so it reads "0" until the
     * coordinator fills it in — and THMP is usually assigned (which creates
     * the Jobber job) before that step, so the raw value would title the job
     * "Zone 0". There is no zone 0; the building's history nearly always
     * knows the real one.
     */
    private function zoneForTitle(WorkOrder $workOrder): ?string
    {
        $own = self::realZone($workOrder->zone);

        if ($own !== null) {
            return $own;
        }

        if (blank($workOrder->building_id)) {
            return null;
        }

        $usual = WorkOrder::withoutGlobalScopes()
            ->where('building_id', $workOrder->building_id)
            ->whereKeyNot($workOrder->getKey())
            ->whereIn('zone', self::ZONES)
            ->select('zone', DB::raw('COUNT(*) as uses'), DB::raw('MAX(id) as newest'))
            ->groupBy('zone')
            ->orderByDesc('uses')
            ->orderByDesc('newest')
            ->value('zone');

        if ($usual === null) {
            Log::info('Jobber job: no zone known for the building; the title carries none.', [
                'work_order_id' => $workOrder->id,
                'building_id' => $workOrder->building_id,
                'propertyware_zone' => $workOrder->zone,
            ]);

            return null;
        }

        return (string) $usual;
    }

    /** The zone as a digit 1–5, or null for PropertyWare's "0", blanks and stray numbers. */
    private static function realZone(mixed $zone): ?string
    {
        $zone = trim((string) $zone);

        return in_array($zone, self::ZONES, true) ? $zone : null;
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
     * POST a GraphQL body to Jobber through the token service (which refreshes
     * on 401 and replays once). Returns the decoded JSON, or null on a failed
     * request (logged) so callers degrade gracefully. A dead Jobber connection
     * throws JobberReconnectRequiredException instead, so the queued job fails
     * and retries later rather than silently giving up.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(array $body): ?array
    {
        $response = $this->tokens->graphql($body);

        if ($response->failed()) {
            Log::error('Jobber job: GraphQL request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }
}
