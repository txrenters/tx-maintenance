<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the tenant's own text after a vendor has been out and says whether
 * the tenant reports the repair done to their satisfaction. The coordinator's
 * "Contact Tenant to Confirm Completion to Tenant Satisfaction" line is
 * ticked only on a confident yes from this agent (Earl, 2026-10-14: the
 * system sends nothing; it only reads what the tenant already wrote).
 *
 * A wrong yes answers the checklist and lets the work order move to
 * Completed - Verified while the tenant is still unhappy, so when in doubt
 * it says no and a coordinator calls the tenant.
 */
class TenantCompletionJudgeAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read text messages between a Texas property management company and a tenant. A vendor or the in-house crew went out for the repair the work order describes.',
            'You are given the work order, the recent thread ("us" = the property manager, "them" = the tenant) and the tenant\'s newest message. Decide whether the TENANT SAYS THE REPAIR IS DONE AND THEY ARE SATISFIED.',
            '',
            'Rules:',
            '- Say satisfied only when the tenant plainly says the work was done and it works now, the problem is gone, or they are happy with the repair.',
            '- A thank-you, an "ok", a question, or a message about the appointment itself (confirming a time, saying the technician arrived) is not a completion.',
            '- Say not satisfied when they say it still does not work, the vendor did not finish or did not show, something was damaged, they want someone back out, or they report a different problem.',
            '- When in doubt, say not satisfied: a wrong yes closes out a repair the tenant is still waiting on.',
            '',
            'satisfied: true only when the tenant reports the repair done and is satisfied.',
            'confidence: 0-100, how sure you are of your decision.',
            'reason: one short plain sentence a coordinator can read, saying what the tenant reported.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'satisfied' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
