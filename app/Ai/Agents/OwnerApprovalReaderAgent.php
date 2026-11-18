<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a work order's description, notes and the owner's messages and says
 * which of its problems the owner approved for repair, quoting the owner.
 * A confident approval sends the work order to the technicians' inspection
 * app with no coordinator step (the office, 2026-11-19), so the rules below
 * err on holding: "if you feel the owner approved it then go; if the
 * message is like not yet, or still unsure, do not send it."
 */
class OwnerApprovalReaderAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read a repair work order from a Texas property management company: its description, the Notes tab, the approval fields, and every message between the office and the property OWNER (email and SMS, oldest first; "office" is the property manager, "owner" is the owner).',
            'Answer one question: WHICH OF THE PROBLEMS ON THIS WORK ORDER HAS THE OWNER APPROVED FOR REPAIR? The owner often approves one item and not the others.',
            '',
            'Rules:',
            '- List every distinct problem the work order describes (e.g. "hallway light", "front door", "lawn"). For each, decide approved, declined or not_mentioned.',
            '- Approved means a clear go-ahead from the owner, in the owner\'s own words: "go ahead", "approved", "yes, fix the light", "do it".',
            '- Anything short of that is NOT approved: "not yet", "hold off", "let me think", "I\'ll get back to you"; a question back to the office with no owner answer after it; a condition the thread does not show met ("ok if under $400" with no estimate under it); silence.',
            '- Only the owner can approve. A tenant\'s yes, a vendor\'s yes, or the office writing "owner says ok" without the owner\'s own message is not approved. An approval of a different work order or an earlier, separate repair at the same property is not approved.',
            '- The LATER MESSAGE WINS: "go ahead" followed by "actually hold off" is not approved.',
            '- Every approved or declined decision MUST quote the owner\'s exact sentence as evidence, with who wrote it and when, copied from the thread. Never paraphrase. not_mentioned has null evidence.',
            '- When in doubt, say not approved. A wrong yes sends a crew to do work the owner has not agreed to pay for.',
            '',
            'problems: one entry per problem, with item, decision (approved | declined | not_mentioned) and evidence (the quote, or null).',
            'approved: true only when at least one problem is approved.',
            'confidence: 0-100, how sure you are of the whole reading.',
            'summary: one or two plain sentences for the technician, saying what the owner approved, declined and did not mention, with the date and channel of the approval.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'problems' => $schema->array()->items(
                $schema->object([
                    'item' => $schema->string()->required(),
                    'decision' => $schema->string()->required(),
                    'evidence' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties()
            )->required(),
            'approved' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
