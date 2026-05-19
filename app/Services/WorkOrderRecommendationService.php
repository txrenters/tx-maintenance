<?php

namespace App\Services;

use App\Ai\Agents\VendorRecommendationAgent;
use App\Ai\Agents\WorkOrderRecommendationAgent;
use App\Models\FallbackVendor;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Promptable;

class WorkOrderRecommendationService
{
    /**
     * @return array{ready: bool, provider: ?string}
     */
    public function aiStatus(): array
    {
        if (! class_exists(Promptable::class)) {
            return [
                'ready' => false,
                'provider' => null,
            ];
        }

        $provider = (string) config('ai.default');
        $providerConfig = config("ai.providers.{$provider}", []);
        $driver = data_get($providerConfig, 'driver');
        $key = (string) data_get($providerConfig, 'key', '');

        $isReady = filled($driver) && filled($key);

        if ($provider === 'azure') {
            $isReady = $isReady && filled(data_get($providerConfig, 'url')) && filled(data_get($providerConfig, 'deployment'));
        }

        return [
            'ready' => $isReady,
            'provider' => $provider !== '' ? $provider : null,
        ];
    }

    /**
     * @return array{
     *     issue_type: string,
     *     issue_subtype: ?string,
     *     vendor_category: ?string,
     *     keywords: array<int, string>,
     *     summary: string,
     *     confidence: int,
     *     needs_human_review: bool,
     *     source: string,
     *     model: ?string,
     *     raw_response: array<string, mixed>|null
     * }
     */
    public function classify(WorkOrder $workOrder, Collection $activeVendors): array
    {
        if (class_exists(Promptable::class)) {
            $classification = $this->classifyWithLaravelAi($workOrder, $activeVendors);

            if ($classification !== null) {
                return $classification;
            }
        }

        return $this->heuristicClassification($workOrder, $activeVendors);
    }

    public function latest(WorkOrder $workOrder): ?WorkOrderRecommendation
    {
        return $workOrder->recommendation()->with('recommendedVendor')->first();
    }

    public function generate(WorkOrder $workOrder): WorkOrderRecommendation
    {
        $workOrder->loadMissing(['vendors', 'managed_by', 'requested_by', 'recommendation.recommendedVendor', 'building']);

        $activeVendors = Vendor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'vendor_type', 'is_active', 'created_at']);

        $classification = $this->classify($workOrder, $activeVendors);
        $matchedHistory = $this->findMatchedHistory($workOrder, $classification['keywords'], $classification['issue_type']);
        $buildingHistory = $this->filterBuildingHistory($matchedHistory, $workOrder);

        [$recommendedVendor, $vendorSource, $vendorReasoning] = $this->pickVendor(
            $workOrder,
            $classification,
            $matchedHistory,
            $buildingHistory,
            $activeVendors,
        );

        $ownerPreferredName = $vendorSource === 'owner_preferred'
            ? $this->extractPreferredVendorName(
                $workOrder->building?->maintenance_notice,
                $classification['issue_type'] ?? null,
            )
            : null;

        $alternateVendors = $this->alternateVendors($recommendedVendor, $classification['vendor_category'], $activeVendors, $matchedHistory);
        $fallbackVendorDetails = $this->fallbackVendorDetails($classification['issue_type'], $classification['keywords'], $recommendedVendor, $alternateVendors);

        return WorkOrderRecommendation::query()->updateOrCreate(
            ['work_order_id' => $workOrder->id],
            [
                'recommended_vendor_id' => $recommendedVendor?->id,
                'status' => 'generated',
                'source' => $classification['source'],
                'model' => $classification['model'],
                'issue_type' => $classification['issue_type'],
                'issue_subtype' => $classification['issue_subtype'],
                'vendor_category' => $classification['vendor_category'],
                'vendor_source' => $vendorSource,
                'confidence' => $classification['confidence'],
                'needs_human_review' => $classification['needs_human_review'],
                'summary' => $classification['summary'],
                'reasoning' => $vendorReasoning ?? $this->buildReasoning($recommendedVendor, $matchedHistory, $classification),
                'keywords' => $classification['keywords'],
                'matched_work_orders' => $matchedHistory->take(5)->map(fn (WorkOrder $history) => $this->formatHistory($history))->values()->all(),
                'alternate_vendors' => [
                    'database' => $alternateVendors,
                    'fallback' => $fallbackVendorDetails,
                ],
                'classification' => Arr::except($classification, ['raw_response']) + [
                    'vendor_source' => $vendorSource,
                    'owner_preferred_name' => $ownerPreferredName,
                    'maintenance_notice' => $workOrder->building?->maintenance_notice,
                ],
                'raw_response' => $classification['raw_response'],
                'generated_at' => now(),
            ]
        )->load('recommendedVendor');
    }

    /**
     * @param  Collection<int, WorkOrder>  $matchedHistory
     * @param  Collection<int, WorkOrder>  $buildingHistory
     * @param  Collection<int, Vendor>  $activeVendors
     * @return array{0: ?Vendor, 1: string, 2: ?string}
     */
    private function pickVendor(
        WorkOrder $workOrder,
        array $classification,
        Collection $matchedHistory,
        Collection $buildingHistory,
        Collection $activeVendors,
    ): array {
        $aiPick = $this->recommendVendorWithAi($workOrder, $classification, $matchedHistory, $buildingHistory, $activeVendors);

        if ($aiPick !== null) {
            return $aiPick;
        }

        return $this->recommendVendorHeuristic($workOrder, $classification, $matchedHistory, $buildingHistory, $activeVendors);
    }

    /**
     * @param  Collection<int, WorkOrder>  $matchedHistory
     * @param  Collection<int, WorkOrder>  $buildingHistory
     * @param  Collection<int, Vendor>  $activeVendors
     * @return array{0: ?Vendor, 1: string, 2: string}|null
     */
    private function recommendVendorWithAi(
        WorkOrder $workOrder,
        array $classification,
        Collection $matchedHistory,
        Collection $buildingHistory,
        Collection $activeVendors,
    ): ?array {
        if (! class_exists(Promptable::class)) {
            return null;
        }

        try {
            $allowedSources = ['owner_preferred', 'building_history', 'cross_site_history', 'category_match', 'fallback'];

            $candidates = $activeVendors
                ->map(fn (Vendor $vendor) => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'vendor_type' => $vendor->vendor_type,
                ])
                ->values()
                ->all();

            $prompt = implode("\n", [
                'Pick the best vendor for this work order using the strict priority order in your instructions.',
                'Issue type: '.$classification['issue_type'],
                'Vendor category hint: '.($classification['vendor_category'] ?? 'N/A'),
                'Work order description: '.($workOrder->description ?? 'N/A'),
                '',
                'Building maintenance notice:',
                $workOrder->building?->maintenance_notice ?? 'N/A',
                '',
                'Building history (prior completed work orders at this same building):',
                $this->summarizeHistory($buildingHistory),
                '',
                'Cross-site history (similar completed work orders at other buildings):',
                $this->summarizeHistory($matchedHistory->reject(fn (WorkOrder $wo) => $buildingHistory->contains('id', $wo->id))),
                '',
                'Candidate active vendors:',
                json_encode($candidates, JSON_UNESCAPED_SLASHES),
            ]);

            $response = (new VendorRecommendationAgent($allowedSources))->prompt($prompt);

            $vendorId = data_get($response, 'vendor_id');
            $source = (string) data_get($response, 'vendor_source', 'fallback');

            if (! in_array($source, $allowedSources, true)) {
                $source = 'fallback';
            }

            $vendor = null;

            if (filled($vendorId)) {
                $vendor = $activeVendors->firstWhere('id', $vendorId);
            }

            if ($vendor === null && filled(data_get($response, 'vendor_name'))) {
                $vendor = $activeVendors->first(
                    fn (Vendor $candidate) => Str::lower($candidate->name) === Str::lower((string) data_get($response, 'vendor_name'))
                );
            }

            $reasoning = (string) (data_get($response, 'reasoning') ?? 'No reasoning provided.');

            return [$vendor, $source, $reasoning];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, WorkOrder>  $matchedHistory
     * @param  Collection<int, WorkOrder>  $buildingHistory
     * @param  Collection<int, Vendor>  $activeVendors
     * @return array{0: ?Vendor, 1: string, 2: ?string}
     */
    private function recommendVendorHeuristic(
        WorkOrder $workOrder,
        array $classification,
        Collection $matchedHistory,
        Collection $buildingHistory,
        Collection $activeVendors,
    ): array {
        $ownerPreferred = $this->matchOwnerPreferredVendor(
            $workOrder->building?->maintenance_notice,
            $classification,
            $activeVendors,
        );

        if ($ownerPreferred instanceof Vendor) {
            return [$ownerPreferred, 'owner_preferred', sprintf(
                'Recommended %s because the building maintenance notice names them as the preferred vendor for %s issues.',
                $ownerPreferred->name,
                $classification['issue_type'],
            )];
        }

        $buildingHistoryVendor = $this->firstActiveVendorFromHistory($buildingHistory, $activeVendors);

        if ($buildingHistoryVendor instanceof Vendor) {
            $sample = $buildingHistory->first();

            return [$buildingHistoryVendor, 'building_history', sprintf(
                'Recommended %s because they completed a similar %s issue at this building on %s.',
                $buildingHistoryVendor->name,
                $classification['issue_type'],
                $this->formatDate($sample?->completed_date),
            )];
        }

        $crossSiteVendor = $this->firstActiveVendorFromHistory(
            $matchedHistory->reject(fn (WorkOrder $wo) => $buildingHistory->contains('id', $wo->id)),
            $activeVendors,
        );

        if ($crossSiteVendor instanceof Vendor) {
            $sample = $matchedHistory->first();

            return [$crossSiteVendor, 'cross_site_history', sprintf(
                'Recommended %s because they completed a similar %s issue at another building on %s.',
                $crossSiteVendor->name,
                $classification['issue_type'],
                $this->formatDate($sample?->completed_date),
            )];
        }

        if (filled($classification['vendor_category'])) {
            $nonServiceTypes = ['broker', 'administrative', 'advertising', 'municipal utility', 'maintenance supplies', 'home warranty', 'professional services', 'txre agent', 'eviction', 'management company'];

            $categoryVendor = $activeVendors->first(
                fn (Vendor $vendor) => filled($vendor->vendor_type)
                    && ! Str::contains(Str::lower($vendor->vendor_type), $nonServiceTypes)
                    && Str::contains(Str::lower($vendor->vendor_type), Str::lower($classification['vendor_category']))
            );

            if ($categoryVendor instanceof Vendor) {
                return [$categoryVendor, 'category_match', sprintf(
                    'Recommended %s because their vendor type matches the %s category.',
                    $categoryVendor->name,
                    $classification['vendor_category'],
                )];
            }
        }

        $configuredFallback = $this->fallbackVendorByIssue($classification['issue_type'], $classification['keywords']);

        if ($configuredFallback !== null) {
            $vendor = $activeVendors->first(
                fn (Vendor $candidate) => Str::lower($candidate->name) === Str::lower($configuredFallback['name'])
            );

            if ($vendor instanceof Vendor) {
                return [$vendor, 'fallback', sprintf(
                    'Recommended %s from the configured fallback list for %s issues.',
                    $vendor->name,
                    $classification['issue_type'],
                )];
            }
        }

        $general = $this->generalFallbackVendor($activeVendors);

        if ($general instanceof Vendor) {
            return [$general, 'fallback', sprintf(
                'No history or owner preference matched; defaulting to %s.',
                $general->name,
            )];
        }

        return [null, 'fallback', 'No active vendor match was found automatically. Human review is required.'];
    }

    /**
     * @param  Collection<int, WorkOrder>  $matchedHistory
     * @return Collection<int, WorkOrder>
     */
    private function filterBuildingHistory(Collection $matchedHistory, WorkOrder $workOrder): Collection
    {
        if (! $workOrder->building_id) {
            return collect();
        }

        return $matchedHistory
            ->filter(fn (WorkOrder $history) => $history->building_id === $workOrder->building_id)
            ->values();
    }

    /**
     * @param  Collection<int, WorkOrder>  $history
     * @param  Collection<int, Vendor>  $activeVendors
     */
    private function firstActiveVendorFromHistory(Collection $history, Collection $activeVendors): ?Vendor
    {
        foreach ($history as $workOrder) {
            $vendor = $workOrder->vendors->first(fn (Vendor $candidate) => $candidate->is_active);

            if ($vendor instanceof Vendor) {
                return $activeVendors->firstWhere('id', $vendor->id) ?? $vendor;
            }
        }

        return null;
    }

    /**
     * Heuristic scan of the maintenance notice for an active vendor name.
     * Prefers a vendor whose type matches the work order category if multiple names appear.
     * Falls back to the placeholder "OWNER VENDOR" record when the notice clearly
     * signals an owner preference but no specific vendor name matches.
     *
     * @param  Collection<int, Vendor>  $activeVendors
     */
    private function matchOwnerPreferredVendor(?string $notice, array $classification, Collection $activeVendors): ?Vendor
    {
        if (blank($notice)) {
            return null;
        }

        $haystack = Str::lower($notice);

        $mentioned = $activeVendors
            ->filter(fn (Vendor $vendor) => filled($vendor->name)
                && Str::lower($vendor->name) !== 'owner vendor'
                && Str::contains($haystack, Str::lower($vendor->name)))
            ->values();

        if ($mentioned->count() === 1) {
            return $mentioned->first();
        }

        if ($mentioned->count() > 1) {
            $category = $classification['vendor_category'] ?? null;
            $issueType = $classification['issue_type'] ?? null;

            return $mentioned->first(function (Vendor $vendor) use ($category, $issueType) {
                if (blank($vendor->vendor_type)) {
                    return false;
                }

                $type = Str::lower($vendor->vendor_type);

                return (filled($category) && Str::contains($type, Str::lower($category)))
                    || (filled($issueType) && Str::contains($type, Str::lower($issueType)));
            }) ?? $mentioned->first();
        }

        if ($this->noticeSignalsOwnerPreference($haystack)) {
            $labels = $this->extractCategoryLabels($notice);
            $issueType = $classification['issue_type'] ?? null;

            if (! empty($labels) && filled($issueType)) {
                $issueLower = Str::lower($issueType);
                $hasMatchingLabel = false;

                foreach ($labels as $label) {
                    if (Str::contains(Str::lower($label), $issueLower) || Str::contains($issueLower, Str::lower($label))) {
                        $hasMatchingLabel = true;
                        break;
                    }
                }

                if (! $hasMatchingLabel) {
                    return null;
                }
            }

            return $activeVendors->first(fn (Vendor $vendor) => Str::lower((string) $vendor->name) === 'owner vendor');
        }

        return null;
    }

    /**
     * Extracts the leading category labels from a maintenance notice.
     * For input "HVAC: PACHECO 832-..; Plumbing: BURNSIDE 832-.." returns ["HVAC", "Plumbing"].
     *
     * @return array<int, string>
     */
    private function extractCategoryLabels(string $notice): array
    {
        $labels = [];
        $segments = preg_split('/[;\n]+/', $notice) ?: [];

        foreach ($segments as $segment) {
            if (preg_match('/^\s*([A-Za-z\/\s&]{2,30}?)\s*[:\-]\s*\S/u', $segment, $match)) {
                $labels[] = trim($match[1]);
            }
        }

        return $labels;
    }

    /**
     * Attempts to extract the preferred-vendor name from the maintenance notice.
     * When an issue type is provided and the notice uses category-labeled segments like
     * "HVAC: VendorName 832-555-1212", the segment matching the issue type wins.
     */
    private function extractPreferredVendorName(?string $notice, ?string $issueType = null): ?string
    {
        if (blank($notice)) {
            return null;
        }

        if (filled($issueType)) {
            $segments = preg_split('/[;\n]+/', $notice) ?: [];

            foreach ($segments as $segment) {
                if (! preg_match('/^\s*([A-Za-z\/\s&]+?)\s*[:\-]\s*(.+)$/u', $segment, $match)) {
                    continue;
                }

                $label = trim($match[1]);
                $body = trim($match[2]);

                if (Str::contains(Str::lower($label), Str::lower($issueType))) {
                    $name = $this->extractNameFromSegment($body);

                    if ($name !== null) {
                        return $name;
                    }
                }
            }
        }

        return $this->extractNameFromSegment($notice);
    }

    private function extractNameFromSegment(string $segment): ?string
    {
        if (preg_match('/\(?\d{3}\)?[\s\-.]?\d{3}[\s\-.]?\d{4}/u', $segment, $match, PREG_OFFSET_CAPTURE)) {
            $before = rtrim(substr($segment, 0, $match[0][1]), " #(\t");
            $words = array_values(array_filter(preg_split('/\s+/', $before) ?: []));

            if (! empty($words)) {
                $filler = ['is', 'the', 'use', 'a', 'an', 'for', 'provider', 'vendor', 'preferred', 'warranty', 'owner', 'hvac', 'plumbing', 'electrical', 'phone'];
                $candidate = array_slice($words, -4);

                while (count($candidate) > 1) {
                    $first = Str::lower(rtrim((string) $candidate[0], ':'));
                    if (! in_array($first, $filler, true)) {
                        break;
                    }
                    array_shift($candidate);
                }

                $name = trim(implode(' ', $candidate), ' :-');

                if ($name !== '') {
                    return $name;
                }
            }
        }

        if (preg_match('/(?:provider|vendor|warranty)[\s:]+([A-Z][\w&\'\-\.\/]+(?:\s+[A-Z]?[\w&\'\-\.\/]+){0,2})/u', $segment, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function noticeSignalsOwnerPreference(string $loweredNotice): bool
    {
        $signals = [
            'vendor',
            'provider',
            'warranty',
            'use only',
            'preferred',
            'do not use',
            'must use',
            'owner prefers',
            'owner\'s',
            'owners ',
        ];

        foreach ($signals as $signal) {
            if (Str::contains($loweredNotice, $signal)) {
                return true;
            }
        }

        // A phone number in the notice strongly suggests a named vendor
        return (bool) preg_match('/\(?\d{3}\)?[\s\-.]?\d{3}[\s\-.]?\d{4}/u', $loweredNotice);
    }

    /**
     * @param  Collection<int, WorkOrder>  $history
     */
    private function summarizeHistory(Collection $history): string
    {
        if ($history->isEmpty()) {
            return 'None.';
        }

        return $history
            ->take(5)
            ->map(function (WorkOrder $workOrder) {
                $vendor = $workOrder->vendors->first();

                return sprintf(
                    '#%s | %s | description: %s | vendor: %s',
                    $workOrder->work_order_no ?? $workOrder->id,
                    $this->formatDate($workOrder->completed_date),
                    Str::limit((string) $workOrder->description, 140),
                    $vendor?->name ?? 'unknown',
                );
            })
            ->implode("\n");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classifyWithLaravelAi(WorkOrder $workOrder, Collection $activeVendors): ?array
    {
        try {
            $vendorTypes = $activeVendors
                ->pluck('vendor_type')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $prompt = $this->buildClassificationPrompt($workOrder, $vendorTypes);
            $response = (new WorkOrderRecommendationAgent($vendorTypes))->prompt($prompt);

            return [
                'issue_type' => (string) data_get($response, 'issue_type', 'General Maintenance'),
                'issue_subtype' => data_get($response, 'issue_subtype'),
                'vendor_category' => data_get($response, 'vendor_category'),
                'keywords' => collect(data_get($response, 'keywords', []))
                    ->filter(fn ($keyword) => filled($keyword))
                    ->map(fn ($keyword) => Str::lower((string) $keyword))
                    ->unique()
                    ->values()
                    ->all(),
                'summary' => (string) data_get($response, 'summary', $this->fallbackSummary($workOrder)),
                'confidence' => max(0, min(100, (int) data_get($response, 'confidence', 0))),
                'needs_human_review' => (bool) data_get($response, 'needs_human_review', false),
                'source' => 'laravel_ai',
                'model' => $this->aiModelName(),
                'raw_response' => method_exists($response, 'toArray') ? $response->toArray() : (array) $response,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     issue_type: string,
     *     issue_subtype: ?string,
     *     vendor_category: ?string,
     *     keywords: array<int, string>,
     *     summary: string,
     *     confidence: int,
     *     needs_human_review: bool,
     *     source: string,
     *     model: ?string,
     *     raw_response: array<string, mixed>|null
     * }
     */
    private function heuristicClassification(WorkOrder $workOrder, Collection $activeVendors): array
    {
        $text = Str::lower(implode("\n", array_filter([
            $workOrder->description,
            $workOrder->type,
            $workOrder->category,
            $workOrder->latest_update_comments,
            $workOrder->closing_comments,
            $workOrder->building?->maintenance_notice,
        ])));

        $rules = [
            'HOA Violation' => ['hoa violation', 'hoa notice', 'deed restriction', 'covenant violation'],
            'Lockout' => ['lockout', 'locked out', 'garage lockout'],
            'Garage Door' => ['garage door', 'garage opener', 'garage stuck'],
            'Lock Repair' => ['front door', 'exit door', 'door lock', 'deadbolt', 'lock replacement', 'lock repair', 'cannot lock', 'cannot unlock'],
            'HVAC' => ['hvac', 'ac', 'a/c', 'air conditioner', 'air conditioning', 'furnace', 'heat', 'heater', 'heating', 'cooling', 'thermostat'],
            'Plumbing' => ['plumb', 'faucet', 'toilet', 'sink', 'drain', 'leak', 'water heater', 'garbage disposal', 'clog'],
            'Electrical' => ['electrical', 'breaker', 'outlet', 'switch', 'power', 'light fixture', 'wiring', 'panel'],
            'Appliance Repair' => ['appliance', 'refrigerator', 'fridge', 'stove', 'oven', 'dishwasher', 'microwave', 'washer', 'dryer'],
            'Locksmith' => ['lock', 'rekey', 'key', 'deadbolt', 'door lock'],
            'Roofing' => ['roof', 'shingle', 'ceiling leak', 'attic leak'],
            'Pest Control' => ['pest', 'roach', 'cockroach', 'mouse', 'mice', 'rat', 'ant', 'termite', 'bed bug'],
            'Cleaning' => ['clean', 'trash out', 'make ready clean', 'deep clean'],
            'Painting' => ['paint', 'repaint', 'touch up', 'touch-up', 'drywall patch'],
            'Landscaping' => ['lawn', 'grass', 'tree', 'stump', 'weeds', 'landscape', 'yard', 'sprinkler', 'irrigation'],
            'General Maintenance' => ['maintenance', 'repair', 'fix', 'issue'],
        ];

        $bestIssueType = 'General Maintenance';
        $bestScore = 0;
        $keywords = collect();

        foreach ($rules as $issueType => $needles) {
            $matched = collect($needles)->filter(fn (string $needle) => $this->keywordMatches($text, $needle));
            $score = $matched->count();

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIssueType = $issueType;
                $keywords = $matched;
            }
        }

        $vendorCategory = $this->guessVendorCategory($bestIssueType, $activeVendors);
        $confidence = $bestScore > 0 ? min(90, 45 + ($bestScore * 15)) : 25;

        return [
            'issue_type' => $bestIssueType,
            'issue_subtype' => $keywords->first(),
            'vendor_category' => $vendorCategory,
            'keywords' => $keywords->take(6)->values()->all(),
            'summary' => $this->fallbackSummary($workOrder),
            'confidence' => $confidence,
            'needs_human_review' => $confidence < 60,
            'source' => 'heuristic',
            'model' => null,
            'raw_response' => null,
        ];
    }

    private function buildClassificationPrompt(WorkOrder $workOrder, array $vendorTypes): string
    {
        return implode("\n", [
            'Classify this maintenance work order for vendor recommendation.',
            'Return the structured schema only.',
            'Use the closest vendor category from this list: '.implode(', ', $vendorTypes),
            'Work order fields:',
            'Description: '.($workOrder->description ?? 'N/A'),
            'Type: '.($workOrder->type ?? 'N/A'),
            'Category: '.($workOrder->category ?? 'N/A'),
            'Latest update comments: '.($workOrder->latest_update_comments ?? 'N/A'),
            'Closing comments: '.($workOrder->closing_comments ?? 'N/A'),
            'Building maintenance notice: '.($workOrder->building?->maintenance_notice ?? 'N/A'),
        ]);
    }

    private function keywordMatches(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/i';

        return (bool) preg_match($pattern, $haystack);
    }

    private function aiModelName(): ?string
    {
        $provider = (string) config('ai.default');

        return match ($provider) {
            'azure' => config('ai.providers.azure.deployment'),
            'openai' => config('services.openai.model'),
            default => $provider !== '' ? $provider : null,
        };
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    private function findMatchedHistory(WorkOrder $workOrder, array $keywords, string $issueType): Collection
    {
        $history = WorkOrder::query()
            ->withoutGlobalScopes()
            ->with(['vendors'])
            ->whereKeyNot($workOrder->id)
            ->whereNotNull('completed_date')
            ->where(function ($query) use ($workOrder, $keywords, $issueType) {
                $query->when($workOrder->building_id, fn ($builder) => $builder->orWhere('building_id', $workOrder->building_id))
                    ->when($workOrder->location, fn ($builder) => $builder->orWhere('location', $workOrder->location))
                    ->orWhere('type', 'like', '%'.$issueType.'%')
                    ->orWhere('category', 'like', '%'.$issueType.'%');

                foreach ($keywords as $keyword) {
                    $query->orWhere('description', 'like', '%'.$keyword.'%')
                        ->orWhere('closing_comments', 'like', '%'.$keyword.'%')
                        ->orWhere('latest_update_comments', 'like', '%'.$keyword.'%');
                }
            })
            ->latest('completed_date')
            ->limit(20)
            ->get();

        return $history->sortByDesc(fn (WorkOrder $historyWorkOrder) => $this->historyScore($workOrder, $historyWorkOrder, $keywords, $issueType))->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alternateVendors(?Vendor $recommendedVendor, ?string $vendorCategory, Collection $activeVendors, Collection $matchedHistory): array
    {
        $historyVendorIds = $matchedHistory
            ->flatMap(fn (WorkOrder $history) => $history->vendors->pluck('id'))
            ->unique();

        return $activeVendors
            ->filter(function (Vendor $vendor) use ($recommendedVendor, $vendorCategory, $historyVendorIds) {
                if ($recommendedVendor && $vendor->id === $recommendedVendor->id) {
                    return false;
                }

                if (filled($vendorCategory) && filled($vendor->vendor_type) && Str::contains(Str::lower($vendor->vendor_type), Str::lower($vendorCategory))) {
                    return true;
                }

                return $historyVendorIds->contains($vendor->id);
            })
            ->take(5)
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'vendor_type' => $vendor->vendor_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, array<string, mixed>>  $databaseAlternates
     * @return array<int, array<string, mixed>>
     */
    private function fallbackVendorDetails(string $issueType, array $keywords, ?Vendor $recommendedVendor, array $databaseAlternates): array
    {
        $configuredVendors = FallbackVendor::active()
            ->with('vendor:id,name')
            ->get()
            ->map(fn (FallbackVendor $fallback) => [
                'name' => $fallback->vendor?->name,
                'vendor_id' => $fallback->vendor_id,
                'contacts' => $fallback->contacts,
                'notes' => $fallback->notes,
                'issue_types' => $fallback->issue_types,
                'keywords' => $fallback->keywords,
                'priority' => $fallback->priority,
            ])
            ->filter(fn (array $vendor) => $vendor['name'] !== null);

        $databaseVendorIds = collect($databaseAlternates)
            ->pluck('id')
            ->when($recommendedVendor !== null, fn ($ids) => $ids->push($recommendedVendor->id))
            ->all();

        return $configuredVendors
            ->filter(function (array $vendor) use ($issueType, $keywords, $databaseVendorIds) {
                if (in_array($vendor['vendor_id'], $databaseVendorIds, true)) {
                    return false;
                }

                if (in_array($issueType, $vendor['issue_types'], true)) {
                    return true;
                }

                return collect($vendor['keywords'] ?? [])
                    ->contains(fn (string $keyword) => in_array(Str::lower($keyword), array_map('strtolower', $keywords), true));
            })
            ->sortBy('priority')
            ->values()
            ->map(fn (array $vendor) => [
                'name' => $vendor['name'],
                'contacts' => $vendor['contacts'] ?? [],
                'notes' => $vendor['notes'] ?? null,
                'issue_types' => $vendor['issue_types'] ?? [],
            ])
            ->all();
    }

    private function buildReasoning(?Vendor $recommendedVendor, Collection $matchedHistory, array $classification): string
    {
        $history = $matchedHistory->first();

        if ($recommendedVendor && $history instanceof WorkOrder && $history->vendors->contains('id', $recommendedVendor->id)) {
            return sprintf(
                'Recommended %s because a similar %s issue was previously completed on %s by the same vendor.',
                $recommendedVendor->name,
                $classification['issue_type'],
                $this->formatDate($history->completed_date),
            );
        }

        if ($recommendedVendor) {
            return sprintf(
                'Recommended %s because its vendor type matches the classified issue category %s.',
                $recommendedVendor->name,
                $classification['vendor_category'] ?? $classification['issue_type'],
            );
        }

        return 'No active vendor match was found automatically. Human review is required.';
    }

    /**
     * @return array<string, mixed>
     */
    private function formatHistory(WorkOrder $history): array
    {
        $vendor = $history->vendors->first();
        $completedDate = $this->normalizeDate($history->completed_date);

        return [
            'id' => $history->id,
            'work_order_no' => $history->work_order_no,
            'description' => $history->description,
            'completed_date' => $completedDate?->toDateString(),
            'vendor' => $vendor ? [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'vendor_type' => $vendor->vendor_type,
            ] : null,
            'closing_comments' => $history->closing_comments,
        ];
    }

    private function historyScore(WorkOrder $current, WorkOrder $history, array $keywords, string $issueType): int
    {
        $score = 0;

        if ($current->building_id && $current->building_id === $history->building_id) {
            $score += 50;
        }

        if (filled($current->location) && $current->location === $history->location) {
            $score += 25;
        }

        if (Str::contains(Str::lower((string) $history->type), Str::lower($issueType))) {
            $score += 20;
        }

        if (Str::contains(Str::lower((string) $history->category), Str::lower($issueType))) {
            $score += 20;
        }

        foreach ($keywords as $keyword) {
            if (
                Str::contains(Str::lower((string) $history->description), Str::lower($keyword)) ||
                Str::contains(Str::lower((string) $history->closing_comments), Str::lower($keyword))
            ) {
                $score += 10;
            }
        }

        $completedDate = $this->normalizeDate($history->completed_date);

        if ($completedDate instanceof CarbonInterface) {
            $score += max(0, 30 - (int) now()->diffInMonths($completedDate));
        }

        return $score;
    }

    private function guessVendorCategory(string $issueType, Collection $activeVendors): ?string
    {
        $mappings = [
            'HOA Violation' => ['landscape', 'lawn', 'handyman', 'maintenance'],
            'Lockout' => ['lock'],
            'Garage Door' => ['garage'],
            'Lock Repair' => ['lock', 'rekey'],
            'HVAC' => ['hvac', 'air'],
            'Plumbing' => ['plumb'],
            'Electrical' => ['electric'],
            'Appliance Repair' => ['appliance'],
            'Locksmith' => ['lock', 'rekey'],
            'Roofing' => ['roof'],
            'Pest Control' => ['pest'],
            'Cleaning' => ['clean'],
            'Painting' => ['paint', 'drywall'],
            'Landscaping' => ['lawn', 'landscape', 'tree'],
            'General Maintenance' => ['maintenance', 'handyman'],
        ];

        $needles = $mappings[$issueType] ?? [];
        $nonServiceTypes = ['broker', 'administrative', 'advertising', 'municipal utility', 'maintenance supplies', 'home warranty', 'professional services', 'txre agent', 'eviction', 'management company'];

        return $activeVendors
            ->pluck('vendor_type')
            ->filter()
            ->reject(fn (string $vendorType) => Str::contains(Str::lower($vendorType), $nonServiceTypes))
            ->first(function (string $vendorType) use ($needles) {
                return collect($needles)->contains(fn (string $needle) => Str::contains(Str::lower($vendorType), $needle));
            });
    }

    private function fallbackSummary(WorkOrder $workOrder): string
    {
        $parts = array_filter([
            $workOrder->description,
            $workOrder->latest_update_comments,
            $workOrder->closing_comments,
        ]);

        return Str::limit(implode(' ', $parts), 280);
    }

    private function formatDate(mixed $date): string
    {
        $normalizedDate = $this->normalizeDate($date);

        if ($normalizedDate instanceof CarbonInterface) {
            return $normalizedDate->toFormattedDateString();
        }

        if (filled($date)) {
            return (string) $date;
        }

        return 'an unknown date';
    }

    private function normalizeDate(mixed $date): ?CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<string, mixed>|null
     */
    private function fallbackVendorByIssue(string $issueType, array $keywords): ?array
    {
        return FallbackVendor::active()
            ->with('vendor:id,name')
            ->get()
            ->map(fn (FallbackVendor $fallback) => [
                'name' => $fallback->vendor?->name,
                'contacts' => $fallback->contacts,
                'notes' => $fallback->notes,
                'issue_types' => $fallback->issue_types,
                'keywords' => $fallback->keywords,
                'priority' => $fallback->priority,
            ])
            ->filter(fn (array $vendor) => $vendor['name'] !== null)
            ->filter(function (array $vendor) use ($issueType, $keywords) {
                if (in_array($issueType, $vendor['issue_types'] ?? [], true)) {
                    return true;
                }

                return collect($vendor['keywords'] ?? [])
                    ->contains(fn (string $keyword) => collect($keywords)->contains(fn (string $match) => Str::contains(Str::lower($match), Str::lower($keyword)) || Str::contains(Str::lower($keyword), Str::lower($match))));
            })
            ->sortBy('priority')
            ->first();
    }

    private function generalFallbackVendor(Collection $activeVendors): ?Vendor
    {
        $generalFallback = FallbackVendor::active()
            ->orderBy('priority')
            ->first();

        if ($generalFallback !== null) {
            $vendor = $activeVendors->first(fn (Vendor $activeVendor) => $activeVendor->id === $generalFallback->vendor_id);

            if ($vendor instanceof Vendor) {
                return $vendor;
            }
        }

        return $activeVendors->first();
    }
}
