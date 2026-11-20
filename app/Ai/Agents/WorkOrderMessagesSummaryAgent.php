<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Summarizes a work order's messages -- tenant, owner and vendor, texts and
 * emails -- for the in-house crew reading it in the inspection app's work
 * order dialog (the office, 2026-11-21). It reports what was said; it never
 * decides anything, and approvals stay with OwnerApprovalReaderAgent.
 */
class WorkOrderMessagesSummaryAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You summarize the messages on one repair work order from a Texas property management company, for the technician who will do the repair.',
            'The messages are texts and emails between the office (the property manager) and the tenant, the owner and the vendors, oldest first. Each line says who wrote it, when and on which channel.',
            '',
            'Write a short summary in plain English, at most five sentences:',
            '- What the problem is, in the tenant\'s and owner\'s words where they add detail the work order does not.',
            '- Access: when the tenant is available, entry permission, pets, and whether a gate or lockbox code was given (say it was given, never the code itself), if mentioned.',
            '- What the owner said about the repair (approved, declined, a cost limit, waiting), if anything.',
            '- What is still open: an unanswered question, a promised call back, a visit not yet confirmed.',
            'Use only what the messages say. Never invent a date, an amount, a name or an approval. Leave out phone numbers, emails, door codes and lockbox codes.',
            'If the messages say nothing useful for the technician, say so in one sentence.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
        ];
    }
}
