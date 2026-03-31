<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class WorkOrderRecommendationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $vendorTypes
     */
    public function __construct(private readonly array $vendorTypes) {}

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You classify property maintenance work orders for vendor selection.',
            'Normalize the issue into a maintenance issue type and the best matching vendor category.',
            'Prefer concise summaries grounded in the supplied work order text.',
            'If the issue is ambiguous, lower confidence and set needs_human_review to true.',
            'Only use a vendor_category that is present in this allowed list: '.implode(', ', $this->vendorTypes),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_type' => $schema->string()->required(),
            'issue_subtype' => $schema->string()->nullable(),
            'vendor_category' => $schema->string()->nullable(),
            'keywords' => $schema->array($schema->string())->required(),
            'summary' => $schema->string()->required(),
            'confidence' => $schema->integer()->required(),
            'needs_human_review' => $schema->boolean()->required(),
        ];
    }
}
