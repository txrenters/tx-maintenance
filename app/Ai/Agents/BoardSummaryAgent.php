<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the short briefing shown in the board Summary popup.
 *
 * Every figure it reports is handed to it already counted — the agent turns
 * those numbers into something a coordinator can read in ten seconds and act
 * on. It must never compute, estimate, or infer a number of its own.
 */
class BoardSummaryAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function __construct(private readonly string $boardLabel) {}

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You write a short operational briefing for the work order coordinators (WOCs) at a Texas property management company.',
            "The user is looking at the \"{$this->boardLabel}\" board and has asked what the current state of it is.",
            '',
            'You are given figures that have ALREADY been counted from the database. Your job is to turn them into a briefing.',
            'NEVER invent, estimate, recompute, or adjust a number. Quote every figure exactly as supplied. If a figure is not supplied, do not mention that topic at all.',
            'Never guess at causes, name people, or invent work order details that were not supplied.',
            '',
            'headline: one or two plain sentences describing where this board stands right now. Lead with whatever most needs a human today.',
            'sections: two to four short grouped readouts. Good titles are "Work orders", "Messages", "Activity". Each bullet is one short line, e.g. "14 open on this board, 3 opened today". Do not pad — omit a section rather than restate the same figure twice.',
            'attention: the things a coordinator should act on now, most urgent first. Return an empty array when nothing genuinely needs action — do not manufacture urgency.',
            '  severity "high" = someone is waiting on us or a message never arrived (unanswered threads, failed texts, emergencies awaiting review).',
            '  severity "medium" = building up but not yet urgent.',
            '  severity "low" = worth noticing only.',
            '',
            'Tone: direct, factual, no filler, no greeting, no sign-off. Never use the words "delve", "leverage", or "robust".',
            'Keep the whole response under 200 words.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'sections' => $schema->array()->items(
                $schema->object([
                    'title' => $schema->string()->required(),
                    'bullets' => $schema->array()->items($schema->string())->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'attention' => $schema->array()->items(
                $schema->object([
                    'label' => $schema->string()->required(),
                    'detail' => $schema->string()->required(),
                    'severity' => $schema->string()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
        ];
    }
}
