<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a tenant's text replies to the Tenant Benefit Package visit notices
 * and says whether the tenant confirmed the visit. A confirmed tenant gets
 * the short "thank you for confirming" reminder instead of the full notice
 * asking them to confirm again (2026-10-23 request, Earl picked "AI reads
 * the reply"). A wrong no only repeats the full notice, so when in doubt it
 * says not confirmed.
 */
class TbpVisitConfirmationJudgeAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read text messages between a Texas property management company and a tenant. The company scheduled a Tenant Benefit Package (TBP) visit (pest control, air filter replacement, occupied inspection) and texted the tenant a notice asking them to confirm by replying.',
            'You are given the visit date and the thread ("us" = the property manager, "them" = the tenant). Decide whether the TENANT CONFIRMED THE VISIT.',
            '',
            'Rules:',
            '- Confirmed when the tenant agrees to or acknowledges the visit: "yes", "ok", "confirmed", "got it", "sounds good", "thank you", a thumbs up, or a tapback like Liked "...".',
            '- Still confirmed when they agree and add a note that does not stop the visit, for example that they will not be home, or that a dog will be crated.',
            '- Not confirmed when they ask to reschedule or cancel, say the date does not work, say they moved out or it is the wrong number, reply STOP, or only ask a question without agreeing.',
            '- Not confirmed when the reply is about something else (a repair request, rent, a different visit).',
            '- When in doubt, say not confirmed: the tenant then gets the notice asking them to confirm again, which is harmless.',
            '',
            'confirmed: true only when the tenant confirmed the visit.',
            'confidence: 0-100, how sure you are of your decision.',
            'reason: one short plain sentence saying what the tenant replied.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirmed' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
