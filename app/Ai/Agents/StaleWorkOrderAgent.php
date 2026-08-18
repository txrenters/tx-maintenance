<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the dossier of one stale work order (30+ days open) — its fields,
 * vendors, schedules, tasks, notes and recent messages — and says why it
 * appears stuck and what the single most useful next step is. Powers the
 * per-row Analyze button on the open-over-30 report: a to-do instead of a
 * bare list.
 *
 * Grounding is the whole game here: a plausible-sounding wrong diagnosis
 * sends a coordinator down the wrong path, so the instructions force every
 * claim onto dossier evidence and make "the system doesn't show why" a
 * first-class answer.
 */
class StaleWorkOrderAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You help work order coordinators at a Texas property management company clear an aging backlog.',
            'You are given the dossier of ONE work order that has been open more than 30 days: its details, assigned vendors, schedules, tasks, internal notes, and the most recent messages.',
            '',
            'stuck_reason: one plain sentence naming what is actually blocking this work order, grounded ONLY in the dossier — e.g. a vendor never scheduled, a tenant stopped answering, an estimate never came back, parts on order, work done but never closed out. Never guess: if the dossier shows no evidence of why, say exactly that ("Nothing in the system shows activity since <date> — cause not visible.").',
            'next_action: one imperative sentence with the single most useful next step for the coordinator ("Call Acme Plumbing for the estimate requested on Jul 2."). It must follow directly from the stuck_reason.',
            '',
            'Never invent vendors, dates, messages, or events that are not in the dossier. Mention dates when the dossier provides them. Keep both fields short enough to read in a glance.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stuck_reason' => $schema->string()->required(),
            'next_action' => $schema->string()->required(),
        ];
    }
}
