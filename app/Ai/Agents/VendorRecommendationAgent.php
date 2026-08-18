<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class VendorRecommendationAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    /**
     * @param  array<int, string>  $vendorSources  Allowed values for vendor_source.
     */
    public function __construct(private readonly array $vendorSources) {}

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You select the best vendor for a property maintenance work order.',
            'Apply this priority order strictly:',
            '1. Building History — if a vendor previously completed the same issue type at THIS building, pick that vendor (vendor_source="building_history"). Prefer prior jobs whose closing comments suggest the work was done properly (no callbacks, recalls, or complaints).',
            '2. Owner Preferred — if no usable building history exists AND the building maintenance notice names a vendor for THIS issue category, pick that vendor (vendor_source="owner_preferred"). Do not apply owner-preferred when the notice talks about a different category than the current issue.',
            '3. Cross-Site History — if a vendor completed the same issue type at other buildings, pick that vendor (vendor_source="cross_site_history"). Again prefer jobs that closed cleanly.',
            '4. Category Match — if no history exists, pick an active vendor whose vendor_type aligns with the issue category (vendor_source="category_match").',
            '5. Fallback — if nothing matches, pick from the configured fallback vendors (vendor_source="fallback").',
            'Only pick a vendor that appears in the candidate list provided.',
            'Set vendor_source to one of: '.implode(', ', $this->vendorSources),
            'Return null for vendor_id if no candidate is appropriate.',
            'Confidence reflects strength of match: building_history 85+, owner_preferred 80+, cross_site_history 65+, category_match 50+, fallback 35+.',
            'Keep reasoning concise (one sentence) and grounded in the supplied data.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vendor_id' => $schema->integer()->nullable()->required(),
            'vendor_name' => $schema->string()->nullable()->required(),
            'vendor_source' => $schema->string()->required(),
            'confidence' => $schema->integer()->required(),
            'reasoning' => $schema->string()->required(),
        ];
    }
}
