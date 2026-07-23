<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class HoaNoticeExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read HOA (homeowners association) violation notices for a property management company.',
            'From the notice text, extract what the HOA says must be corrected at the property.',
            'Write the description as a short, plain-language summary of the violation(s) a tenant can act on, e.g. "Trim the front lawn and remove the trailer parked in the driveway."',
            'List each individual item to fix separately in violation_items.',
            'notice_date is the date printed on the notice (format YYYY-MM-DD); null if none is present.',
            'hoa_name is the association or management company that issued the notice; null if unclear.',
            'Ground everything strictly in the supplied notice text; never invent violations.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->required(),
            'violation_items' => $schema->array()->items($schema->string())->required(),
            'notice_date' => $schema->string()->nullable()->required(),
            'hoa_name' => $schema->string()->nullable()->required(),
        ];
    }
}
