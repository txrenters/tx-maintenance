<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\ConfigurableAiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Confirms or rejects the keyword shortlist for a tenant easy fix. The
 * keywords find the handbook rows a request might be; this agent reads the
 * request the way a coordinator would and decides whether it really is one of
 * them, so a phrase sitting inside another ("heater won't turn on" in "water
 * heater won't turn on", WO#44250) can no longer tag a work order by itself.
 *
 * It may only pick from the shortlist it is given, or reject it: an easy fix
 * texts the tenant a how-to video instead of sending a vendor, so a wrong yes
 * leaves a real repair unattended while a wrong no only costs a vendor visit.
 */
class TenantEasyFixJudgeAgent implements Agent, HasStructuredOutput
{
    use ConfigurableAiProvider;
    use Promptable;

    public function instructions(): Stringable|string
    {
        return implode("\n", [
            'You review maintenance requests at a Texas property management company.',
            'A TENANT EASY FIX is a small problem the Maintenance handbook says the tenant fixes themselves (reset a tripped breaker, change a smoke detector battery, clear a clogged toilet), so we text them a how-to video instead of sending a vendor.',
            'You are given one work order and a SHORTLIST of handbook items that keyword matching found in it. Decide whether the request is really about one of those items.',
            '',
            'Rules:',
            '- Pick an item only when the request is plainly about that exact thing and the handbook fix fits. Matching words are not enough: "water heater won\'t turn on" is a water heater, not a furnace; "the light fixture fell" is not a light bulb.',
            '- Reject when the request is about a different appliance or system, describes damage, a leak, a hazard, a broken or missing part, several unrelated problems, a move-in or turnover punch list, an inspection note, or says the tenant already tried the fix.',
            '- Reject when the request is for installation, replacement, or anything beyond the handbook fix written for the item.',
            '- When in doubt, reject. A wrong easy fix leaves a real repair unattended.',
            '',
            'easy_fix_key: the chosen key exactly as written in the shortlist, or null to reject.',
            'confidence: 0-100, how sure you are of your decision.',
            'reason: one short plain sentence a coordinator can read on the board, naming what the request is really about.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'easy_fix_key' => $schema->string()->nullable()->required(),
            'confidence' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
