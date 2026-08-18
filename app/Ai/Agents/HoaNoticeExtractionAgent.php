<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads an uploaded HOA violation document that may contain SEVERAL notices —
 * different properties, different associations, different deadlines — and
 * returns one structured entry per notice. A single-notice file simply yields
 * an array of one.
 */
class HoaNoticeExtractionAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read HOA (homeowners association) violation notices for a property management company.',
            'A single uploaded document may contain MULTIPLE separate notices — different properties, different associations, and different deadlines. Return one entry in "notices" for EACH distinct property notice you find. A document with only one notice yields exactly one entry.',
            'The notice text is provided with "===== PAGE N =====" markers. For each notice, set "page" to the page number where that notice begins.',
            'property_address: the street address the notice is about (the "Property:" line), e.g. "10107 Mariposa Green Ct". Do NOT use the mailing/recipient address. Null if none is present.',
            'description: a short, plain-language summary of what the tenant must correct, e.g. "Store the trash bins out of view on non-trash days." Keep it actionable.',
            'violation_items: each individual item to fix, listed separately.',
            'hoa_name: the association or management company that issued the notice; null if unclear.',
            'notice_date: the date printed on the notice (YYYY-MM-DD); null if none.',
            'deadline_date: an explicit "remedy by / resolve by" calendar date if the notice states one (YYYY-MM-DD); null otherwise.',
            'deadline_days: if the notice instead says to fix it within a number of days (e.g. "within the next 10 days"), the integer number of days; null otherwise.',
            'Ground everything strictly in the supplied text; never invent properties, violations, or dates.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'notices' => $schema->array()->items(
                $schema->object([
                    'page' => $schema->integer()->required(),
                    'property_address' => $schema->string()->nullable()->required(),
                    'description' => $schema->string()->required(),
                    'violation_items' => $schema->array()->items($schema->string())->required(),
                    'hoa_name' => $schema->string()->nullable()->required(),
                    'notice_date' => $schema->string()->nullable()->required(),
                    'deadline_date' => $schema->string()->nullable()->required(),
                    'deadline_days' => $schema->integer()->nullable()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
        ];
    }
}
