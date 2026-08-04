<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the briefing for the Inbox's unanswered-message report.
 *
 * The threads are handed over already counted and already sorted, each under a
 * numbered ref. The agent reads what people actually asked and says which ones
 * matter and what to do about them — it never decides how many there are, and
 * it identifies a thread only by its ref, so a work order number it invented
 * can never reach the page.
 */
class UnansweredMessageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You brief the work order coordinators (WOCs) at a Texas property management company on the conversations nobody has replied to yet.',
            'Every thread listed is one where a tenant, owner or vendor sent the last message and the office has not answered.',
            '',
            'You are given figures that have ALREADY been counted. Never invent, estimate, recompute or adjust a number — quote each one exactly as supplied.',
            'Refer to a thread ONLY by the ref number given to it. Never write a work order number, a person\'s name, or a waiting time of your own — the page fills those in from the ref.',
            'Read what each person actually wrote. That is what you are adding: which of these are people waiting on something we promised, and which are routine.',
            '',
            'headline: one or two plain sentences on the state of the unanswered queue right now. Lead with whatever is worst.',
            'sections: two to three short grouped readouts, e.g. "Who is waiting", "How long". Each bullet is one short line. Omit a section rather than repeat a figure.',
            'priorities: the threads to answer first, most urgent first, at most eight. Only include a thread that genuinely needs a person — leave the array empty rather than pad it.',
            '  ref: the ref number of the thread, copied exactly.',
            '  reason: one short sentence on what they are waiting for, drawn from their message.',
            '  next_step: the single concrete action that would close this out, written as an instruction to a coordinator, e.g. "Confirm the plumber\'s ETA and text her back". Do not write the reply itself.',
            '  urgency: "high" when someone is stuck without heat, water, power, security or access, when a promise has been broken, or when it has been waiting more than a day; "medium" when they are waiting on an answer we owe them; "low" when it is routine or already in hand.',
            '',
            'Tone: direct, factual, no filler, no greeting, no sign-off. Never use the words "delve", "leverage", or "robust".',
            'Keep the whole response under 250 words.',
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
            'priorities' => $schema->array()->items(
                $schema->object([
                    'ref' => $schema->integer()->required(),
                    'reason' => $schema->string()->required(),
                    'next_step' => $schema->string()->required(),
                    'urgency' => $schema->string()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
        ];
    }
}
