<?php

namespace App\Services;

use App\Ai\Agents\WorkOrderRecommendationAgent;
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
        $workOrder->loadMissing(['vendors', 'managed_by', 'requested_by', 'recommendation.recommendedVendor']);

        $activeVendors = Vendor::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'vendor_type', 'is_active', 'created_at']);

        $classification = $this->classify($workOrder, $activeVendors);
        $matchedHistory = $this->findMatchedHistory($workOrder, $classification['keywords'], $classification['issue_type']);
        $recommendedVendor = $this->determineRecommendedVendor($classification, $matchedHistory, $activeVendors);
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
                'confidence' => $classification['confidence'],
                'needs_human_review' => $classification['needs_human_review'],
                'summary' => $classification['summary'],
                'reasoning' => $this->buildReasoning($recommendedVendor, $matchedHistory, $classification),
                'keywords' => $classification['keywords'],
                'matched_work_orders' => $matchedHistory->take(5)->map(fn (WorkOrder $history) => $this->formatHistory($history))->values()->all(),
                'alternate_vendors' => [
                    'database' => $alternateVendors,
                    'fallback' => $fallbackVendorDetails,
                ],
                'classification' => Arr::except($classification, ['raw_response']),
                'raw_response' => $classification['raw_response'],
                'generated_at' => now(),
            ]
        )->load('recommendedVendor');
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
                'model' => config('services.openai.model'),
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
        ])));

        $rules = [
            'Lockout' => ['lockout', 'locked out', 'garage lockout'],
            'Garage Door' => ['garage door', 'garage opener', 'garage stuck'],
            'Lock Repair' => ['front door', 'exit door', 'door lock', 'deadbolt', 'lock replacement', 'lock repair', 'cannot lock', 'cannot unlock'],
            'HVAC' => ['hvac', 'ac', 'a/c', 'air conditioner', 'air conditioning', 'furnace', 'heat', 'cooling', 'thermostat'],
            'Plumbing' => ['plumb', 'faucet', 'toilet', 'sink', 'drain', 'leak', 'water heater', 'garbage disposal', 'clog'],
            'Electrical' => ['electrical', 'breaker', 'outlet', 'switch', 'power', 'light fixture', 'wiring', 'panel'],
            'Appliance Repair' => ['appliance', 'refrigerator', 'fridge', 'stove', 'oven', 'dishwasher', 'microwave', 'washer', 'dryer'],
            'Locksmith' => ['lock', 'rekey', 'key', 'deadbolt', 'door lock'],
            'Roofing' => ['roof', 'shingle', 'ceiling leak', 'attic leak'],
            'Pest Control' => ['pest', 'roach', 'cockroach', 'mouse', 'mice', 'rat', 'ant', 'termite', 'bed bug'],
            'Cleaning' => ['clean', 'trash out', 'make ready clean', 'deep clean'],
            'Painting' => ['paint', 'repaint', 'touch up', 'touch-up', 'drywall patch'],
            'Landscaping' => ['lawn', 'grass', 'tree', 'landscape', 'yard', 'sprinkler', 'irrigation'],
            'General Maintenance' => ['maintenance', 'repair', 'fix', 'issue'],
        ];

        $bestIssueType = 'General Maintenance';
        $bestScore = 0;
        $keywords = collect();

        foreach ($rules as $issueType => $needles) {
            $score = collect($needles)
                ->filter(fn (string $needle) => Str::contains($text, $needle))
                ->count();

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIssueType = $issueType;
                $keywords = collect($needles)->filter(fn (string $needle) => Str::contains($text, $needle));
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
        ]);
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

    private function determineRecommendedVendor(array $classification, Collection $matchedHistory, Collection $activeVendors): ?Vendor
    {
        $historyVendor = $matchedHistory
            ->map(function (WorkOrder $history) {
                return $history->vendors
                    ->first(fn (Vendor $vendor) => $vendor->is_active);
            })
            ->filter()
            ->first();

        if ($historyVendor instanceof Vendor) {
            return $activeVendors->firstWhere('id', $historyVendor->id) ?? $historyVendor;
        }

        $mappedFallback = $this->fallbackVendorByIssue($classification['issue_type'], $classification['keywords']);

        if ($mappedFallback !== null) {
            return $activeVendors->first(
                fn (Vendor $vendor) => Str::lower($vendor->name) === Str::lower($mappedFallback['name'])
            );
        }

        if (blank($classification['vendor_category'])) {
            return $this->generalFallbackVendor($activeVendors);
        }

        $databaseVendor = $activeVendors
            ->first(fn (Vendor $vendor) => filled($vendor->vendor_type) && Str::contains(Str::lower($vendor->vendor_type), Str::lower($classification['vendor_category'])));

        return $databaseVendor ?? $this->generalFallbackVendor($activeVendors);
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
        $configuredVendors = collect(config('work_order_recommendations.fallback_vendors', []))
            ->map(function (array $vendor, string $name) {
                return [
                    'name' => $name,
                    ...$vendor,
                ];
            });

        $databaseVendorNames = collect($databaseAlternates)
            ->pluck('name')
            ->when($recommendedVendor !== null, fn ($names) => $names->push($recommendedVendor->name))
            ->map(fn ($name) => Str::lower((string) $name))
            ->all();

        return $configuredVendors
            ->filter(function (array $vendor) use ($issueType, $keywords, $databaseVendorNames) {
                if (in_array(Str::lower($vendor['name']), $databaseVendorNames, true)) {
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
            $score += max(0, 30 - now()->diffInMonths($completedDate));
        }

        return $score;
    }

    private function guessVendorCategory(string $issueType, Collection $activeVendors): ?string
    {
        $mappings = [
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

        return $activeVendors
            ->pluck('vendor_type')
            ->filter()
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
        return collect(config('work_order_recommendations.fallback_vendors', []))
            ->map(function (array $vendor, string $name) {
                return [
                    'name' => $name,
                    ...$vendor,
                ];
            })
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
        $generalFallback = collect(config('work_order_recommendations.fallback_vendors', []))
            ->keys()
            ->first(fn (string $name) => $name === 'RA Solutions');

        if ($generalFallback !== null) {
            $vendor = $activeVendors->first(fn (Vendor $activeVendor) => Str::lower($activeVendor->name) === Str::lower($generalFallback));

            if ($vendor instanceof Vendor) {
                return $vendor;
            }
        }

        return $activeVendors->first();
    }
}
