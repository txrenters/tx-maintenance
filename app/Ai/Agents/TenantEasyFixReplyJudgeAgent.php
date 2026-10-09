<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads the tenant's text reply after the easy-fix how-to and says whether
 * the tenant reports the problem fixed. A yes only labels the board card
 * "Ready to close" for a coordinator (Earl, 2026-10-09): nothing is closed
 * and no status moves on this verdict, so the label has to be worth the
 * coordinator's trust. When in doubt it says not fixed.
 */
class TenantEasyFixReplyJudgeAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You read text messages between a Texas property management company and a tenant. The tenant reported a small problem the handbook says they can fix themselves, and the company texted them a how-to video and then checked in on it.',
            'You are given the work order, the handbook item, the recent thread ("us" = the property manager, "them" = the tenant) and the tenant\'s newest message. Decide whether the TENANT SAYS THE PROBLEM IS FIXED and no repair visit is needed.',
            '',
            'Rules:',
            '- Say resolved only when the tenant plainly says it works now, the problem is gone, or they no longer need anyone to come out.',
            '- A thank-you, an "ok", or "I will try it" is not a fix. A question is not a fix.',
            '- Say not resolved when they say it still does not work, it worked briefly, they want someone to come out, or they report a different problem.',
            '- When in doubt, say not resolved: a wrong yes tells a coordinator to close a repair that is still open.',
            '',
            'resolved: true only when the tenant reports the problem fixed.',
            'confidence: 0-100, how sure you are of your decision.',
            'reason: one short plain sentence a coordinator can read, saying what the tenant reported.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resolved' => $schema->boolean()->required(),
            'confidence' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
